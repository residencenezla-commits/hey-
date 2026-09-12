//! CSV loading, with the input timezone declared rather than guessed.

use crate::types::{Bar, DayMeta};
use chrono::{DateTime, Datelike, NaiveDateTime, TimeZone, Timelike};
use chrono_tz::US::Eastern;
use std::collections::HashMap;
use std::fs;
use std::path::{Path, PathBuf};

/// How to interpret the timestamps in the input files.
///
/// The previous loader stripped any trailing UTC offset and then handed the
/// remainder to `Utc.from_utc_datetime`. That is correct only when the offset
/// was `+00:00`: for a file exported as `2024-03-15 09:30:00-04:00` it displaces
/// every bar by four or five hours, and the displacement changes across daylight
/// saving boundaries. Since the file cannot be trusted to say which it is, the
/// caller declares it and the loader verifies.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum TzInput {
    /// Naive timestamps are UTC. An explicit offset must be `+00:00`.
    Utc,
    /// Timestamps carry an explicit offset, which is honoured.
    Offset,
    /// Naive timestamps are already US/Eastern wall-clock time.
    Eastern,
}

impl TzInput {
    pub fn parse(s: &str) -> Self {
        match s.trim().to_lowercase().as_str() {
            "utc" => TzInput::Utc,
            "offset" => TzInput::Offset,
            "et" | "eastern" => TzInput::Eastern,
            other => panic!("--tz-input must be one of utc|offset|et, got '{other}'"),
        }
    }
    pub fn as_str(&self) -> &'static str {
        match self {
            TzInput::Utc => "utc",
            TzInput::Offset => "offset",
            TzInput::Eastern => "et",
        }
    }
}

#[derive(Default, Debug)]
pub struct LoadReport {
    pub bad_rows: usize,
    pub offset_rows: usize,
    pub naive_rows: usize,
    pub ambiguous_local: usize,
}

const FMTS_NAIVE: [&str; 4] = [
    "%Y-%m-%d %H:%M:%S",
    "%Y-%m-%d %H:%M",
    "%Y-%m-%dT%H:%M:%S",
    "%Y-%m-%dT%H:%M",
];
const FMTS_OFFSET: [&str; 4] = [
    "%Y-%m-%d %H:%M:%S%#z",
    "%Y-%m-%dT%H:%M:%S%#z",
    "%Y-%m-%d %H:%M:%S%.f%#z",
    "%Y-%m-%dT%H:%M:%S%.f%#z",
];

/// Convert one timestamp field to ET minutes-from-midnight plus a date code.
///
/// Returns `None` when the field cannot be parsed, or when it disagrees with
/// the declared `tz` (for example an explicit `-04:00` under `--tz-input utc`).
pub fn parse_et(ts: &str, tz: TzInput, rep: &mut LoadReport) -> Option<(i32, u32, i32)> {
    let ts = ts.trim();

    // Offset-aware first: an explicit offset is unambiguous and we want to know
    // it is there even when the caller said the file was naive UTC.
    for f in FMTS_OFFSET {
        if let Ok(dt) = DateTime::parse_from_str(ts, f) {
            rep.offset_rows += 1;
            match tz {
                TzInput::Utc if dt.offset().local_minus_utc() != 0 => {
                    // Declared UTC but carries a real offset: refuse rather than
                    // silently shifting every bar.
                    return None;
                }
                _ => {}
            }
            let et = dt.with_timezone(&Eastern);
            return Some((
                et.hour() as i32 * 60 + et.minute() as i32,
                et.year() as u32 * 10000 + et.month() * 100 + et.day(),
                et.year(),
            ));
        }
    }

    let naive: NaiveDateTime = FMTS_NAIVE.iter().find_map(|f| NaiveDateTime::parse_from_str(ts, f).ok())?;
    rep.naive_rows += 1;

    let et = match tz {
        TzInput::Utc | TzInput::Offset => chrono::Utc.from_utc_datetime(&naive).with_timezone(&Eastern),
        TzInput::Eastern => match Eastern.from_local_datetime(&naive) {
            chrono::LocalResult::Single(dt) => dt,
            chrono::LocalResult::Ambiguous(a, _) => {
                // The autumn repeated hour. Take the first occurrence and count it.
                rep.ambiguous_local += 1;
                a
            }
            chrono::LocalResult::None => {
                // The spring skipped hour — this wall-clock time does not exist.
                rep.ambiguous_local += 1;
                return None;
            }
        },
    };
    Some((
        et.hour() as i32 * 60 + et.minute() as i32,
        et.year() as u32 * 10000 + et.month() * 100 + et.day(),
        et.year(),
    ))
}

/// Files for one instrument, matched on an anchored `{SYMBOL}_` prefix.
///
/// An unanchored `contains("ES")` also matches `MES_2024.CSV`, `RESULTS.CSV`
/// and `NQ_ES_SPREAD.CSV`.
pub fn instrument_files(dir: &Path, symbol: &str) -> Vec<PathBuf> {
    let sym = symbol.to_uppercase();
    let mut out: Vec<PathBuf> = Vec::new();
    let entries = match fs::read_dir(dir) {
        Ok(e) => e,
        Err(e) => panic!("cannot read --data-dir {}: {e}", dir.display()),
    };
    for entry in entries.flatten() {
        let name = entry.file_name().to_string_lossy().to_uppercase();
        if !name.ends_with(".CSV") {
            continue;
        }
        let stem = name.trim_end_matches(".CSV");
        let matches = stem == sym
            || stem.strip_prefix(&sym).is_some_and(|r| r.starts_with(['_', '-']))
            || stem.starts_with(&format!("{sym}_"))
            || stem.starts_with(&format!("{sym}-"));
        if matches {
            out.push(entry.path());
        }
    }
    out.sort();
    out
}

/// Load bars and group them into calendar days.
///
/// OHLC fields are parsed strictly: a row with an unparseable or nonsensical
/// price is skipped and counted, never coerced to zero.
pub fn load_data(fps: &[PathBuf], tz: TzInput) -> (Vec<Bar>, Vec<DayMeta>, LoadReport) {
    let mut bars: Vec<Bar> = Vec::new();
    let mut days: Vec<DayMeta> = Vec::new();
    let mut dmap: HashMap<u32, u32> = HashMap::new();
    let mut nid: u32 = 0;
    let mut rep = LoadReport::default();

    for fp in fps {
        println!("  Loading {}...", fp.file_name().unwrap_or_default().to_string_lossy());
        let content = match fs::read_to_string(fp) {
            Ok(c) => c,
            Err(e) => panic!("cannot read {}: {e}", fp.display()),
        };
        for (i, line) in content.lines().enumerate() {
            if i == 0 {
                continue;
            }
            let line = line.trim_end_matches('\r');
            if line.trim().is_empty() {
                continue;
            }
            let f: Vec<&str> = line.split(',').collect();
            if f.len() < 7 {
                rep.bad_rows += 1;
                continue;
            }
            let Some((tm, dk, year)) = parse_et(f[1], tz, &mut rep) else {
                rep.bad_rows += 1;
                continue;
            };
            let (Ok(high), Ok(low), Ok(close)) =
                (f[3].trim().parse::<f64>(), f[4].trim().parse::<f64>(), f[5].trim().parse::<f64>())
            else {
                rep.bad_rows += 1;
                continue;
            };
            let vol = f[6].trim().parse::<f64>().unwrap_or(0.0);
            if !(high.is_finite() && low.is_finite() && close.is_finite())
                || high <= 0.0
                || low <= 0.0
                || close <= 0.0
                || low > high
                || close > high
                || close < low
            {
                rep.bad_rows += 1;
                continue;
            }
            let did = *dmap.entry(dk).or_insert_with(|| {
                let id = nid;
                days.push(DayMeta { year, date_code: dk, start: 0, end: 0, sess_start: 0, sess_end: 0 });
                nid += 1;
                id
            });
            bars.push(Bar { high, low, close, volume: vol, time_mins: tm, date_id: did });
        }
    }

    if !bars.is_empty() {
        let mut cur = bars[0].date_id;
        days[cur as usize].start = 0;
        for (i, b) in bars.iter().enumerate() {
            if b.date_id != cur {
                days[cur as usize].end = i;
                cur = b.date_id;
                days[cur as usize].start = i;
            }
        }
        days[cur as usize].end = bars.len();
    }

    if rep.bad_rows > 0 {
        eprintln!("  WARNING: skipped {} malformed or out-of-range rows", rep.bad_rows);
    }
    let total = rep.offset_rows + rep.naive_rows;
    if total > 0 && rep.offset_rows > 0 && rep.naive_rows > 0 {
        eprintln!(
            "  WARNING: mixed timestamp formats — {} rows carried an explicit offset, {} did not",
            rep.offset_rows, rep.naive_rows
        );
    }
    if tz == TzInput::Utc && rep.bad_rows > 0 && rep.offset_rows > 0 {
        eprintln!(
            "  HINT: --tz-input utc rejects rows carrying a non-zero offset. \
             If your export is offset-aware, rerun with --tz-input offset."
        );
    }
    if rep.ambiguous_local > 0 {
        eprintln!("  NOTE: {} rows fell in a DST transition hour", rep.ambiguous_local);
    }
    println!("  {} bars, {} days ({} timestamps)", bars.len(), days.len(), tz.as_str());
    (bars, days, rep)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The defect this module exists to prevent: a timestamp carrying an
    /// explicit Eastern offset must not be reinterpreted as UTC.
    #[test]
    fn offset_timestamps_round_trip_to_the_right_session_minute() {
        let mut rep = LoadReport::default();
        // 09:30 Eastern on a summer date, written with its offset.
        let (tm, dk, yr) = parse_et("2024-07-15 09:30:00-04:00", TzInput::Offset, &mut rep).unwrap();
        assert_eq!(tm, 570, "09:30 ET must be minute 570, not shifted");
        assert_eq!(dk, 20240715);
        assert_eq!(yr, 2024);

        // Winter date, offset -05:00.
        let (tm, dk, _) = parse_et("2024-01-16 09:30:00-05:00", TzInput::Offset, &mut rep).unwrap();
        assert_eq!(tm, 570, "09:30 ET must be minute 570 in winter too");
        assert_eq!(dk, 20240116);
    }

    #[test]
    fn utc_timestamps_convert_across_both_dst_phases() {
        let mut rep = LoadReport::default();
        // 13:30 UTC is 09:30 EDT in July.
        let (tm, dk, _) = parse_et("2024-07-15 13:30:00", TzInput::Utc, &mut rep).unwrap();
        assert_eq!(tm, 570);
        assert_eq!(dk, 20240715);
        // 14:30 UTC is 09:30 EST in January.
        let (tm, dk, _) = parse_et("2024-01-16 14:30:00", TzInput::Utc, &mut rep).unwrap();
        assert_eq!(tm, 570);
        assert_eq!(dk, 20240116);
    }

    #[test]
    fn eastern_wall_clock_is_taken_literally() {
        let mut rep = LoadReport::default();
        for (s, expect) in [("2024-07-15 09:30:00", 570), ("2024-01-16 09:30:00", 570)] {
            let (tm, _, _) = parse_et(s, TzInput::Eastern, &mut rep).unwrap();
            assert_eq!(tm, expect);
        }
    }

    #[test]
    fn declaring_utc_on_offset_data_fails_loudly() {
        let mut rep = LoadReport::default();
        assert!(
            parse_et("2024-07-15 09:30:00-04:00", TzInput::Utc, &mut rep).is_none(),
            "a non-zero offset under --tz-input utc must be rejected, not silently shifted"
        );
        // A +00:00 suffix is consistent with UTC and is accepted.
        assert!(parse_et("2024-07-15 13:30:00+00:00", TzInput::Utc, &mut rep).is_some());
    }

    #[test]
    fn t_separated_iso_timestamps_parse() {
        let mut rep = LoadReport::default();
        let (tm, _, _) = parse_et("2024-07-15T13:30:00", TzInput::Utc, &mut rep).unwrap();
        assert_eq!(tm, 570);
        let (tm, _, _) = parse_et("2024-07-15T09:30:00-04:00", TzInput::Offset, &mut rep).unwrap();
        assert_eq!(tm, 570);
    }

    #[test]
    fn instrument_matching_is_anchored() {
        let dir = std::env::temp_dir().join(format!("orb_match_{}", std::process::id()));
        let _ = fs::create_dir_all(&dir);
        for n in ["ES_2024.csv", "MES_2024.csv", "RESULTS.csv", "NQ_ES_SPREAD.csv", "ES-2025.csv", "NQ_2024.csv"] {
            let _ = fs::write(dir.join(n), "h\n");
        }
        let got: Vec<String> = instrument_files(&dir, "ES")
            .iter()
            .map(|p| p.file_name().unwrap().to_string_lossy().to_string())
            .collect();
        let _ = fs::remove_dir_all(&dir);
        assert!(got.contains(&"ES_2024.csv".to_string()));
        assert!(got.contains(&"ES-2025.csv".to_string()));
        assert!(!got.iter().any(|n| n.starts_with("MES")), "MES must not match ES: {got:?}");
        assert!(!got.iter().any(|n| n.starts_with("RESULTS")), "RESULTS must not match ES: {got:?}");
        assert!(!got.iter().any(|n| n.contains("SPREAD")), "spread file must not match ES: {got:?}");
        assert_eq!(got.len(), 2, "{got:?}");
    }
}
