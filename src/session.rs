//! Session resolution and session-day construction.
//!
//! Two jobs. First, turn `--session` into a pair of minutes-from-midnight ET
//! bounds. Second — and this is the load-bearing part — establish for every day
//! the half-open range of bars that lie *inside* that session, so the backtest
//! is structurally unable to trade outside it.

use crate::types::{Bar, DayMeta};
use chrono::{Datelike, NaiveDate};

/// Resolve a session name (or explicit override) to ET minutes-from-midnight.
///
/// A `close_mins` above 1440 means the session wraps past midnight and closes
/// `close_mins - 1440` into the next calendar day.
pub fn resolve_session(name: &str, open_override: i32, close_override: i32) -> (i32, i32) {
    if open_override >= 0 && close_override >= 0 {
        assert!(
            close_override > open_override,
            "--session-close ({close_override}) must be after --session-open ({open_override}); \
             for a session that wraps midnight pass a close above 1440, e.g. 20:00-02:00 is 1200 and 1560"
        );
        assert!(
            close_override - open_override <= 1440,
            "session length {} minutes exceeds 24 hours",
            close_override - open_override
        );
        return (open_override, close_override);
    }
    match name.to_lowercase().as_str() {
        "ny" => (570, 960),          // 09:30-16:00
        "london" => (180, 480),      // 03:00-08:00
        "asia" => (1200, 1560),      // 20:00-02:00, wraps
        "globex24" => (1080, 2460),  // 18:00-17:00, wraps
        other => panic!(
            "Unknown session '{other}'. Use ny|london|asia|globex24, \
             or pass --session-open and --session-close."
        ),
    }
}

/// True if a bar at `t_mins` (0..1439) falls inside the session.
pub fn in_session(t_mins: i32, open_mins: i32, close_mins: i32) -> bool {
    if close_mins > 1440 {
        let close_wrapped = close_mins - 1440;
        t_mins >= open_mins || t_mins < close_wrapped
    } else {
        t_mins >= open_mins && t_mins < close_mins
    }
}

/// Rebuild calendar days into session-days for sessions that wrap midnight.
///
/// For non-wrapping sessions this is a pass-through: one session-day equals one
/// calendar day. For wrapping sessions (asia, globex24) bars from 20:00 on date
/// D and 01:00 on D+1 belong to the same session, so the day grouping produced
/// by the loader is wrong and has to be redone.
///
/// Weekend and holiday gaps are detected from true calendar-day ordinals rather
/// than the loader's sequential `date_id`: futures do not trade Saturdays, so no
/// Saturday bars exist and Friday/Sunday receive consecutive ids, which makes an
/// id-difference test blind to the largest gap in the week.
pub fn rebuild_session_days(
    bars: &mut [Bar],
    days_in: &[DayMeta],
    open_mins: i32,
    close_mins: i32,
) -> Vec<DayMeta> {
    if close_mins <= 1440 {
        return days_in.to_vec();
    }
    if bars.is_empty() {
        return Vec::new();
    }
    let close_wrapped = close_mins - 1440;

    let date_code_to_ord = |dc: u32| -> i64 {
        let y = (dc / 10000) as i32;
        let m = (dc / 100) % 100;
        let d = dc % 100;
        NaiveDate::from_ymd_opt(y, m, d)
            .map(|nd| nd.num_days_from_ce() as i64)
            .unwrap_or(0)
    };
    let cal_ord: Vec<i64> = days_in.iter().map(|d| date_code_to_ord(d.date_code)).collect();

    let mut new_days: Vec<DayMeta> = Vec::new();
    let mut session_id: u32 = 0;
    let mut session_start: usize = 0;
    let mut session_year: i32 = days_in[bars[0].date_id as usize].year;
    let mut open = false;
    let mut new_did: Vec<u32> = vec![u32::MAX; bars.len()];

    let push_day = |v: &mut Vec<DayMeta>, year: i32, code: u32, s: usize, e: usize| {
        v.push(DayMeta { year, date_code: code, start: s, end: e, sess_start: s, sess_end: e });
    };

    for i in 0..bars.len() {
        let t = bars[i].time_mins;
        let did = bars[i].date_id as usize;
        let inside = t >= open_mins || t < close_wrapped;

        let calendar_gap = i > 0 && {
            let prev_ord = cal_ord[bars[i - 1].date_id as usize];
            (cal_ord[did] - prev_ord) > 1
        };

        if calendar_gap && open {
            let code = days_in[bars[session_start].date_id as usize].date_code;
            push_day(&mut new_days, session_year, code, session_start, i);
            session_id += 1;
            open = false;
        }

        if !inside {
            if open {
                let code = days_in[bars[session_start].date_id as usize].date_code;
                push_day(&mut new_days, session_year, code, session_start, i);
                session_id += 1;
                open = false;
            }
            continue;
        }

        if !open {
            session_start = i;
            session_year = days_in[did].year;
            open = true;
        } else {
            // Crossing back up through the open boundary after the wrapped
            // portion ended means a new session has begun.
            let prev_t = bars[i - 1].time_mins;
            if prev_t < close_wrapped && t >= open_mins {
                let code = days_in[bars[session_start].date_id as usize].date_code;
                push_day(&mut new_days, session_year, code, session_start, i);
                session_id += 1;
                session_start = i;
                session_year = days_in[did].year;
            }
        }
        new_did[i] = session_id;
    }

    if open {
        let code = days_in[bars[session_start].date_id as usize].date_code;
        push_day(&mut new_days, session_year, code, session_start, bars.len());
    }

    for i in 0..bars.len() {
        if new_did[i] != u32::MAX {
            bars[i].date_id = new_did[i];
        }
    }

    if (new_days.len() as f64) < (days_in.len() as f64) * 0.5 {
        eprintln!(
            "  WARNING: session-day rebuild produced {} days from {} calendar days. \
             That is suspiciously low — gap detection may have failed. Verify on a small subset.",
            new_days.len(),
            days_in.len()
        );
    }
    new_days
}

/// Populate `sess_start`/`sess_end` on every day: the half-open bar range that
/// lies inside the session.
///
/// Bars within a day are time-ordered, and for a non-wrapping session the
/// in-session bars therefore form one contiguous run — so a scan for the first
/// and last qualifying bar is exact. For wrapping sessions `rebuild_session_days`
/// has already trimmed each day to in-session bars only.
pub fn index_sessions(bars: &[Bar], days: &mut [DayMeta], open_mins: i32, close_mins: i32) {
    for d in days.iter_mut() {
        if close_mins > 1440 {
            d.sess_start = d.start;
            d.sess_end = d.end;
            continue;
        }
        let mut s = d.end;
        let mut e = d.start;
        for i in d.start..d.end {
            if in_session(bars[i].time_mins, open_mins, close_mins) {
                if s == d.end {
                    s = i;
                }
                e = i + 1;
            }
        }
        if s == d.end {
            // No in-session bars at all.
            d.sess_start = d.start;
            d.sess_end = d.start;
        } else {
            d.sess_start = s;
            d.sess_end = e;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn bar(t: i32, did: u32) -> Bar {
        Bar { high: 101.0, low: 99.0, close: 100.0, volume: 1.0, time_mins: t, date_id: did }
    }
    fn day(year: i32, code: u32, s: usize, e: usize) -> DayMeta {
        DayMeta { year, date_code: code, start: s, end: e, sess_start: s, sess_end: e }
    }

    #[test]
    fn named_sessions_resolve() {
        assert_eq!(resolve_session("ny", -1, -1), (570, 960));
        assert_eq!(resolve_session("NY", -1, -1), (570, 960));
        assert_eq!(resolve_session("london", -1, -1), (180, 480));
        assert_eq!(resolve_session("asia", -1, -1), (1200, 1560));
        assert_eq!(resolve_session("globex24", -1, -1), (1080, 2460));
        assert_eq!(resolve_session("ny", 100, 200), (100, 200));
    }

    #[test]
    fn inverted_custom_session_is_rejected() {
        assert!(std::panic::catch_unwind(|| resolve_session("ny", 600, 300)).is_err());
    }

    #[test]
    fn in_session_handles_wrap() {
        // NY: 09:30-16:00
        assert!(!in_session(569, 570, 960));
        assert!(in_session(570, 570, 960));
        assert!(in_session(959, 570, 960));
        assert!(!in_session(960, 570, 960)); // close is exclusive
        assert!(!in_session(1080, 570, 960)); // 18:00 overnight — outside

        // Asia: 20:00-02:00 wrapped
        assert!(in_session(1200, 1200, 1560));
        assert!(in_session(1439, 1200, 1560));
        assert!(in_session(0, 1200, 1560));
        assert!(in_session(119, 1200, 1560));
        assert!(!in_session(120, 1200, 1560));
        assert!(!in_session(600, 1200, 1560));
    }

    /// The defect this whole module exists to prevent: for a 24-hour instrument
    /// keyed by calendar day, the session range must exclude the overnight bars.
    #[test]
    fn index_sessions_excludes_overnight_bars() {
        // One calendar day of a 24h instrument: 00:00, 09:29, 09:30, 15:59,
        // 16:00, 18:00, 23:59.
        let bars: Vec<Bar> = [0, 569, 570, 959, 960, 1080, 1439]
            .iter()
            .map(|&t| bar(t, 0))
            .collect();
        let mut days = vec![day(2024, 20240315, 0, bars.len())];
        index_sessions(&bars, &mut days, 570, 960);
        assert_eq!(days[0].sess_start, 2, "session must start at 09:30, not 00:00");
        assert_eq!(days[0].sess_end, 4, "session must end at 16:00, not 23:59");
        assert_eq!(days[0].session_len(), 2);
        for i in days[0].sess_start..days[0].sess_end {
            assert!(in_session(bars[i].time_mins, 570, 960));
        }
    }

    #[test]
    fn index_sessions_handles_day_with_no_session_bars() {
        let bars = vec![bar(0, 0), bar(100, 0)];
        let mut days = vec![day(2024, 20240315, 0, 2)];
        index_sessions(&bars, &mut days, 570, 960);
        assert_eq!(days[0].session_len(), 0);
    }

    #[test]
    fn non_wrapped_rebuild_is_passthrough() {
        let mut bars = vec![bar(600, 0), bar(700, 0)];
        let days = vec![day(2024, 20240315, 0, 2)];
        let out = rebuild_session_days(&mut bars, &days, 570, 960);
        assert_eq!(out.len(), 1);
        assert_eq!(out[0].date_code, 20240315);
    }

    /// Friday evening and Sunday evening are different sessions even though the
    /// loader gives them consecutive date_ids (there is no Saturday data).
    #[test]
    fn wrapped_rebuild_splits_across_the_weekend() {
        let mut bars = vec![
            bar(1200, 0), // Fri 20:00
            bar(1300, 0), // Fri 21:40
            bar(1200, 1), // Sun 20:00  <- +2 calendar days
            bar(60, 2),   // Mon 01:00, same session as Sunday evening
        ];
        let days = vec![
            day(2024, 20240315, 0, 2), // Friday
            day(2024, 20240317, 2, 3), // Sunday
            day(2024, 20240318, 3, 4), // Monday
        ];
        let out = rebuild_session_days(&mut bars, &days, 1200, 1560);
        assert_eq!(out.len(), 2, "expected Friday and Sunday-into-Monday sessions");
        assert_eq!(out[0].start, 0);
        assert_eq!(out[0].end, 2);
        assert_eq!(out[1].start, 2);
        assert_eq!(out[1].end, 4);
        assert_eq!(bars[3].date_id, 1, "Monday 01:00 belongs to the Sunday session");
    }
}
