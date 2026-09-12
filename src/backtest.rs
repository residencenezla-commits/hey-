//! The opening-range-breakout backtest.
//!
//! Two behavioural fixes live here, both of which change every number
//! downstream because the Monte Carlo simulators resample this output:
//!
//! 1. Every scan is bounded to `DayMeta::sess_start .. sess_end`. The previous
//!    loop ran from the end of the opening range to the end of the *calendar
//!    day*; on a 24-hour instrument that is roughly a thousand out-of-session
//!    bars, and a wick-break trigger among them fills at the stale opening-range
//!    level while exiting at the current close — booking any overnight gap over
//!    that level as profit on a fill that could not have happened.
//!
//! 2. A wick-break entry whose own bar also trades through the stop is recorded
//!    as a losing trade. It was previously discarded, and the loop broke, so the
//!    day produced nothing — censoring the worst-performing subset of the
//!    population out of win rate, profit factor and expectancy alike.

// A NaN-bearing threshold test is written as `!(x >= lo)` rather than `x < lo`
// on purpose: an unmeasurable feature must FAIL a filter, and every comparison
// against NaN is false, so the negated form is the one that rejects it.
#![allow(clippy::neg_cmp_op_on_partial_ord)]

use crate::features::{compute_entropy, day_eligible};
use crate::types::{
    Bar, BiasMode, DayMeta, EntryMode, ExitMode, InstCfg, RawTrade, MAX_OR_TICKS, MIN_OR_TICKS,
};
use std::collections::HashMap;

/// Minutes elapsed since the session opened, wrapping past midnight.
///
/// Monotonic across a session even when it spans midnight, which makes one
/// comparison work for both wrapped and non-wrapped sessions. Comparing raw
/// `time_mins` against `close_mins` silently never fires for a wrapped session,
/// because `close_mins` there exceeds 1440 and `time_mins` never does.
#[inline]
pub fn mins_since_open(t_mins: i32, open_mins: i32) -> i32 {
    (t_mins - open_mins).rem_euclid(1440)
}

/// Per-(window, day) precomputation.
///
/// The opening range, its entropy and the first post-range bar depend only on
/// the window length and the day — not on the target multiple, direction, exit
/// mode, filter thresholds or regime. Computing them once instead of once per
/// grid cell removes the dominant cost of a sweep; sample entropy in particular
/// is O(n^2) in the window length and was previously recomputed for every
/// combination that shared a window.
pub struct DayCache {
    pub windows: Vec<i32>,
    pub n_days: usize,
    oh: Vec<f64>,
    ol: Vec<f64>,
    ent: Vec<f64>,
    ps: Vec<u32>,
}

impl DayCache {
    pub fn build(bars: &[Bar], days: &[DayMeta], inst: &InstCfg, mut windows: Vec<i32>) -> Self {
        windows.sort_unstable();
        windows.dedup();
        let n_days = days.len();
        let n = windows.len() * n_days;
        let mut oh = vec![f64::NAN; n];
        let mut ol = vec![f64::NAN; n];
        let mut ent = vec![f64::NAN; n];
        let mut ps = vec![u32::MAX; n];

        for (wi, &w) in windows.iter().enumerate() {
            let base = wi * n_days;
            for (d, day) in days.iter().enumerate() {
                let (s, e) = (day.sess_start, day.sess_end);
                if e <= s {
                    continue;
                }
                let mut hi = f64::NEG_INFINITY;
                let mut lo = f64::INFINITY;
                let mut closes: Vec<f64> = Vec::new();
                let mut first_post = u32::MAX;
                for i in s..e {
                    let m = mins_since_open(bars[i].time_mins, inst.open_mins);
                    if m < w {
                        if bars[i].high > hi { hi = bars[i].high; }
                        if bars[i].low < lo { lo = bars[i].low; }
                        closes.push(bars[i].close);
                    } else if first_post == u32::MAX {
                        first_post = i as u32;
                    }
                }
                if hi.is_finite() && lo.is_finite() {
                    oh[base + d] = hi;
                    ol[base + d] = lo;
                }
                ps[base + d] = first_post;
                if closes.len() >= 5 {
                    let mut rets = Vec::with_capacity(closes.len());
                    for k in 1..closes.len() {
                        if closes[k - 1] > 0.0 && closes[k] > 0.0 {
                            rets.push((closes[k] / closes[k - 1]).ln());
                        }
                    }
                    if rets.len() >= 4 {
                        ent[base + d] = compute_entropy(&rets);
                    }
                }
            }
        }
        DayCache { windows, n_days, oh, ol, ent, ps }
    }

    pub fn window_index(&self, w: i32) -> usize {
        self.windows
            .iter()
            .position(|&x| x == w)
            .unwrap_or_else(|| panic!("window {w} was not precomputed"))
    }

    #[inline]
    pub fn get(&self, wi: usize, d: usize) -> (f64, f64, f64, u32) {
        let k = wi * self.n_days + d;
        (self.oh[k], self.ol[k], self.ent[k], self.ps[k])
    }
}

/// First and last day index (half-open) for each calendar year.
///
/// Days are chronological, so each year occupies a contiguous span. Looking the
/// span up beats re-scanning all days inside every fold.
pub fn year_spans(days: &[DayMeta]) -> HashMap<i32, (usize, usize)> {
    let mut m: HashMap<i32, (usize, usize)> = HashMap::new();
    for (d, day) in days.iter().enumerate() {
        m.entry(day.year).and_modify(|e| e.1 = d + 1).or_insert((d, d + 1));
    }
    m
}

/// Day-index span covering an inclusive year range.
pub fn span_for_years(spans: &HashMap<i32, (usize, usize)>, ymin: i32, ymax: i32) -> (usize, usize) {
    let mut lo = usize::MAX;
    let mut hi = 0usize;
    for y in ymin..=ymax {
        if let Some(&(a, b)) = spans.get(&y) {
            lo = lo.min(a);
            hi = hi.max(b);
        }
    }
    if lo == usize::MAX { (0, 0) } else { (lo, hi) }
}

pub struct BacktestParams<'a> {
    pub window: i32,
    pub rr: f64,
    pub direction: &'a str,
    pub cost_1ct: f64,
    pub h_min: f64,
    pub e_max: f64,
    pub year_range: Option<(i32, i32)>,
    pub entry_mode: EntryMode,
    pub bias_mode: BiasMode,
    pub exit_mode: ExitMode,
    pub lux_tper: f64,
    pub vol_regime_filter: &'a str,
}

/// Run the strategy over `day_span`, returning one-contract trades.
#[allow(clippy::too_many_arguments)]
pub fn backtest(
    bars: &[Bar],
    days: &[DayMeta],
    inst: &InstCfg,
    cache: &DayCache,
    p: &BacktestParams,
    day_regimes: &[u8],
    hurst_prev: &[f64],
    day_span: (usize, usize),
) -> Vec<RawTrade> {
    let wi = cache.window_index(p.window);
    let want_long = p.direction == "long" || p.direction == "both";
    let want_short = p.direction == "short" || p.direction == "both";
    let mut trades = Vec::new();
    let mut prev_orm = f64::NAN;

    for d in day_span.0..day_span.1.min(days.len()) {
        // A year boundary is a hard reset: the previous day's opening-range
        // midpoint must not leak across it, so skip before touching prev_orm.
        if let Some((ymin, ymax)) = p.year_range {
            if days[d].year < ymin || days[d].year > ymax {
                continue;
            }
        }

        let (oh, ol, ent, ps_raw) = cache.get(wi, d);
        if !oh.is_finite() || !ol.is_finite() {
            continue;
        }
        let orr = oh - ol;
        let orm = (oh + ol) / 2.0;

        // Direction bias compares today's midpoint with the most recent prior
        // year-eligible day, so it must be read before prev_orm is updated —
        // and prev_orm must advance for every day with a valid range, so that
        // days skipped by a regime or threshold filter behave the same as
        // traded days for the days that follow.
        let day_dir: i8 = if prev_orm.is_nan() {
            0
        } else if orm > prev_orm {
            1
        } else if orm < prev_orm {
            -1
        } else {
            0
        };
        prev_orm = orm;

        if !day_eligible(day_regimes[d], p.vol_regime_filter) {
            continue;
        }

        let rt = orr / inst.tick_size;
        if !(MIN_OR_TICKS..=MAX_OR_TICKS).contains(&rt) {
            continue;
        }

        // A Hurst or entropy value that could not be estimated is NaN, and NaN
        // fails these comparisons — an unmeasurable day is not traded when the
        // filter is active, rather than clearing it on a sentinel.
        if p.h_min > 0.0 && !(hurst_prev[d] >= p.h_min) {
            continue;
        }
        if p.e_max > 0.0 && !(ent <= p.e_max) {
            continue;
        }

        let sess_end = days[d].sess_end;
        if ps_raw == u32::MAX {
            continue;
        }
        let ps = ps_raw as usize;
        if ps >= sess_end || sess_end - ps < 5 {
            continue;
        }

        let long_entry_level = match p.bias_mode {
            BiasMode::NoBias => oh,
            BiasMode::DailyBias => if day_dir == -1 { oh + p.lux_tper * orr } else { oh },
        };
        let short_entry_level = match p.bias_mode {
            BiasMode::NoBias => ol,
            BiasMode::DailyBias => if day_dir == 1 { ol - p.lux_tper * orr } else { ol },
        };
        let long_stop = ol;
        let short_stop = oh;

        if want_long {
            if let Some(t) = run_side(
                bars, ps, sess_end, true, long_entry_level, long_stop, orr, orm, p, inst, d,
            ) {
                trades.push(t);
            }
        }
        if want_short {
            if let Some(t) = run_side(
                bars, ps, sess_end, false, short_entry_level, short_stop, orr, orm, p, inst, d,
            ) {
                trades.push(t);
            }
        }
    }
    trades
}

/// One direction for one day. `lo..hi` is the post-range slice of the session,
/// and nothing outside it is ever read.
#[allow(clippy::too_many_arguments)]
fn run_side(
    bars: &[Bar],
    lo: usize,
    hi: usize,
    is_long: bool,
    entry_level: f64,
    stop: f64,
    orr: f64,
    orm: f64,
    p: &BacktestParams,
    inst: &InstCfg,
    day_idx: usize,
) -> Option<RawTrade> {
    let dir: u8 = if is_long { 0 } else { 1 };
    let pv = inst.point_value;
    let mk = |pnl: f64| RawTrade { pnl_1ct: pnl, risk_1ct: orr, dir, day_idx };

    let mut entry_at: Option<(usize, f64)> = None;
    for i in lo..hi {
        let b = &bars[i];
        let triggered = match p.entry_mode {
            EntryMode::WickBreak => {
                if is_long { b.high > entry_level } else { b.low < entry_level }
            }
            EntryMode::CloseCross => {
                if i == lo {
                    if is_long { b.close > entry_level } else { b.close < entry_level }
                } else {
                    let pc = bars[i - 1].close;
                    if is_long {
                        pc <= entry_level && b.close > entry_level
                    } else {
                        pc >= entry_level && b.close < entry_level
                    }
                }
            }
        };
        if !triggered {
            continue;
        }
        match p.entry_mode {
            EntryMode::WickBreak => {
                // Filled intrabar at the level. If this same bar also traded
                // through the stop the sequence is unknowable from OHLC, so take
                // the adverse one: a stop-out, recorded as the loss it is.
                let same_bar_stop = if is_long { b.low <= stop } else { b.high >= stop };
                if same_bar_stop {
                    let pnl = if is_long {
                        (stop - entry_level) * pv - p.cost_1ct
                    } else {
                        (entry_level - stop) * pv - p.cost_1ct
                    };
                    return Some(mk(pnl));
                }
                entry_at = Some((i, entry_level));
            }
            EntryMode::CloseCross => {
                // Filled on the close, so this bar's range is already history and
                // no same-bar stop is possible.
                entry_at = Some((i, b.close));
            }
        }
        break;
    }
    let (i0, entry_px) = entry_at?;
    let _ = orm;

    let target_px = match p.exit_mode {
        ExitMode::FixedRR => {
            if is_long { entry_px + p.rr * orr } else { entry_px - p.rr * orr }
        }
        ExitMode::LuxTargets => {
            if is_long { entry_px + p.lux_tper * orr } else { entry_px - p.lux_tper * orr }
        }
    };

    for i in (i0 + 1)..hi {
        let b = &bars[i];
        let hit_stop = if is_long { b.low <= stop } else { b.high >= stop };
        if hit_stop {
            let pnl = if is_long { (stop - entry_px) * pv } else { (entry_px - stop) * pv };
            return Some(mk(pnl - p.cost_1ct));
        }
        let hit_target = if is_long { b.high >= target_px } else { b.low <= target_px };
        if hit_target {
            let pnl = if is_long { (target_px - entry_px) * pv } else { (entry_px - target_px) * pv };
            return Some(mk(pnl - p.cost_1ct));
        }
    }

    // Ran out of session: exit at the session's closing price. There is no need
    // for a wall-clock time check — the slice already ends at the session close.
    let last = bars[hi - 1].close;
    let pnl = if is_long { (last - entry_px) * pv } else { (entry_px - last) * pv };
    Some(mk(pnl - p.cost_1ct))
}

pub fn group_trades_by_day(trades: &[RawTrade]) -> Vec<Vec<RawTrade>> {
    if trades.is_empty() {
        return Vec::new();
    }
    let mut m: HashMap<usize, Vec<RawTrade>> = HashMap::new();
    for t in trades {
        m.entry(t.day_idx).or_default().push(*t);
    }
    let mut keys: Vec<usize> = m.keys().copied().collect();
    keys.sort_unstable();
    keys.into_iter().map(|k| m.remove(&k).unwrap()).collect()
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::session::index_sessions;
    use crate::types::get_inst;

    fn b(t: i32, did: u32, high: f64, low: f64, close: f64) -> Bar {
        Bar { high, low, close, volume: 1.0, time_mins: t, date_id: did }
    }

    fn params<'a>(entry: EntryMode, exit: ExitMode) -> BacktestParams<'a> {
        BacktestParams {
            window: 30,
            rr: 1.0,
            direction: "long",
            cost_1ct: 0.0,
            h_min: 0.0,
            e_max: 0.0,
            year_range: None,
            entry_mode: entry,
            bias_mode: BiasMode::NoBias,
            exit_mode: exit,
            lux_tper: 1.0,
            vol_regime_filter: "all",
        }
    }

    /// One NY day of a 24-hour instrument. The opening range is 09:30-10:00 and
    /// is never broken during the session, but price gaps far above it in the
    /// evening. A correct engine takes no trade.
    fn overnight_gap_day() -> (Vec<Bar>, Vec<DayMeta>) {
        let mut bars = Vec::new();
        // Overnight before the open — must be invisible.
        for t in [0, 120, 300, 560] {
            bars.push(b(t, 0, 5000.0, 4990.0, 4995.0));
        }
        // Opening range 09:30-09:59: 5000 / 4990.
        for t in 570..600 {
            bars.push(b(t, 0, 5000.0, 4990.0, 4995.0));
        }
        // Session 10:00-15:59: stays strictly inside the range.
        for t in 600..960 {
            bars.push(b(t, 0, 4999.0, 4991.0, 4995.0));
        }
        // Evening: gaps 60 points above the range high.
        for t in [1080, 1200, 1380, 1439] {
            bars.push(b(t, 0, 5060.0, 5055.0, 5058.0));
        }
        let n = bars.len();
        let mut days = vec![DayMeta {
            year: 2024, date_code: 20240315, start: 0, end: n, sess_start: 0, sess_end: n,
        }];
        index_sessions(&bars, &mut days, 570, 960);
        (bars, days)
    }

    #[test]
    fn no_trade_is_taken_outside_the_session() {
        let (bars, days) = overnight_gap_day();
        let inst = get_inst("NQ");
        let cache = DayCache::build(&bars, &days, &inst, vec![30]);
        let p = params(EntryMode::WickBreak, ExitMode::FixedRR);
        let trades = backtest(&bars, &days, &inst, &cache, &p, &[0], &[f64::NAN], (0, 1));
        assert!(
            trades.is_empty(),
            "the range was never broken in-session; the 60-point evening gap must not fill: {trades:?}"
        );
    }

    /// Whatever trades are produced, their entry and exit must lie inside the
    /// session window. This is the general form of the defect.
    #[test]
    fn every_trade_lies_inside_the_session_window() {
        let (bars, days) = overnight_gap_day();
        let inst = get_inst("NQ");
        let cache = DayCache::build(&bars, &days, &inst, vec![30]);
        let (s, e) = (days[0].sess_start, days[0].sess_end);
        for i in s..e {
            let m = mins_since_open(bars[i].time_mins, inst.open_mins);
            assert!(m < 960 - 570, "bar at {} is outside the NY session", bars[i].time_mins);
        }
        let mut p = params(EntryMode::WickBreak, ExitMode::FixedRR);
        p.direction = "both";
        let trades = backtest(&bars, &days, &inst, &cache, &p, &[0], &[f64::NAN], (0, 1));
        for t in &trades {
            assert_eq!(t.day_idx, 0);
        }
    }

    /// A wick-break entry whose own bar sweeps the whole range is a loss, not a
    /// silently dropped signal.
    #[test]
    fn same_bar_stop_out_is_recorded_as_a_loss() {
        let mut bars = Vec::new();
        for t in 570..600 {
            bars.push(b(t, 0, 5000.0, 4990.0, 4995.0));
        }
        // 10:00 — one bar spanning above the range high and below the range low.
        bars.push(b(600, 0, 5010.0, 4980.0, 4985.0));
        for t in 601..960 {
            bars.push(b(t, 0, 4995.0, 4993.0, 4994.0));
        }
        let n = bars.len();
        let mut days = vec![DayMeta {
            year: 2024, date_code: 20240315, start: 0, end: n, sess_start: 0, sess_end: n,
        }];
        index_sessions(&bars, &mut days, 570, 960);

        let inst = get_inst("NQ");
        let cache = DayCache::build(&bars, &days, &inst, vec![30]);
        let p = params(EntryMode::WickBreak, ExitMode::FixedRR);
        let trades = backtest(&bars, &days, &inst, &cache, &p, &[0], &[f64::NAN], (0, 1));

        assert_eq!(trades.len(), 1, "the sweep bar must produce exactly one trade");
        let t = trades[0];
        assert!(t.pnl_1ct < 0.0, "a same-bar sweep is a loss, got {}", t.pnl_1ct);
        // Entry at the range high 5000, stop at the range low 4990: -10 points.
        assert!((t.pnl_1ct - (-10.0 * inst.point_value)).abs() < 1e-9, "got {}", t.pnl_1ct);
    }

    /// A close-cross entry fills at the bar's close, so the bar's low is already
    /// history and must not be treated as a same-bar stop.
    #[test]
    fn close_cross_entry_has_no_same_bar_stop() {
        let mut bars = Vec::new();
        for t in 570..600 {
            bars.push(b(t, 0, 5000.0, 4990.0, 4995.0));
        }
        bars.push(b(600, 0, 5010.0, 4980.0, 5005.0)); // sweeps low, closes above the high
        for t in 601..960 {
            bars.push(b(t, 0, 5006.0, 5004.0, 5005.0));
        }
        let n = bars.len();
        let mut days = vec![DayMeta {
            year: 2024, date_code: 20240315, start: 0, end: n, sess_start: 0, sess_end: n,
        }];
        index_sessions(&bars, &mut days, 570, 960);
        let inst = get_inst("NQ");
        let cache = DayCache::build(&bars, &days, &inst, vec![30]);
        let p = params(EntryMode::CloseCross, ExitMode::FixedRR);
        let trades = backtest(&bars, &days, &inst, &cache, &p, &[0], &[f64::NAN], (0, 1));
        assert_eq!(trades.len(), 1);
        // Entered at 5005, drifts sideways, exits at the session close 5005: flat.
        assert!(trades[0].pnl_1ct.abs() < 1e-9, "got {}", trades[0].pnl_1ct);
    }

    /// The ceiling used throughout the report and the EV model: no trade can
    /// net more than `max_target_mult * MAX_OR_TICKS * tick * point_value`.
    #[test]
    fn no_trade_exceeds_the_engine_ceiling() {
        use rand::{Rng, SeedableRng};
        let mut rng = rand::rngs::StdRng::seed_from_u64(99);
        let inst = get_inst("NQ");
        let lux = 1.5;
        let ceiling = inst.per_trade_ceiling(lux, 0, 0.0);

        let mut bars = Vec::new();
        let mut px = 18000.0f64;
        for t in 570..960 {
            px *= 1.0 + (rng.gen::<f64>() - 0.5) * 0.004;
            let hi = px * (1.0 + rng.gen::<f64>() * 0.002);
            let lo = px * (1.0 - rng.gen::<f64>() * 0.002);
            bars.push(b(t, 0, hi, lo, px.clamp(lo, hi)));
        }
        let n = bars.len();
        let mut days = vec![DayMeta {
            year: 2024, date_code: 20240315, start: 0, end: n, sess_start: 0, sess_end: n,
        }];
        index_sessions(&bars, &mut days, 570, 960);
        let cache = DayCache::build(&bars, &days, &inst, vec![5, 30, 60]);

        for &w in &[5, 30, 60] {
            for dirn in ["long", "short", "both"] {
                let mut p = params(EntryMode::WickBreak, ExitMode::LuxTargets);
                p.window = w;
                p.direction = dirn;
                p.lux_tper = lux;
                let trades = backtest(&bars, &days, &inst, &cache, &p, &[0], &[f64::NAN], (0, 1));
                for t in trades {
                    assert!(
                        t.pnl_1ct <= ceiling + 1e-6,
                        "trade of ${:.2} exceeds the ${ceiling:.2} ceiling (w={w}, {dirn})",
                        t.pnl_1ct
                    );
                }
            }
        }
    }

    #[test]
    fn mins_since_open_is_monotonic_across_midnight() {
        // Asia opens 20:00.
        assert_eq!(mins_since_open(1200, 1200), 0);
        assert_eq!(mins_since_open(1439, 1200), 239);
        assert_eq!(mins_since_open(0, 1200), 240);
        assert_eq!(mins_since_open(119, 1200), 359);
        // NY opens 09:30.
        assert_eq!(mins_since_open(570, 570), 0);
        assert_eq!(mins_since_open(600, 570), 30);
        assert_eq!(mins_since_open(959, 570), 389);
    }

    #[test]
    fn year_spans_are_contiguous_and_exclusive() {
        let days: Vec<DayMeta> = [2021, 2021, 2022, 2022, 2022, 2023]
            .iter()
            .enumerate()
            .map(|(i, &y)| DayMeta { year: y, date_code: 0, start: i, end: i + 1, sess_start: i, sess_end: i + 1 })
            .collect();
        let spans = year_spans(&days);
        assert_eq!(spans[&2021], (0, 2));
        assert_eq!(spans[&2022], (2, 5));
        assert_eq!(spans[&2023], (5, 6));
        assert_eq!(span_for_years(&spans, 2021, 2022), (0, 5));
        assert_eq!(span_for_years(&spans, 2030, 2031), (0, 0));
    }
}
