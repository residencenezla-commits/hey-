//! Core data types shared across the engine.

/// One price bar. `time_mins` is minutes-from-midnight in US/Eastern,
/// `date_id` indexes into the `DayMeta` slice the bar belongs to.
#[derive(Clone, Debug)]
pub struct Bar {
    pub high: f64,
    pub low: f64,
    pub close: f64,
    pub volume: f64,
    pub time_mins: i32,
    pub date_id: u32,
}

/// One trading day (calendar day, or session-day for wrapped sessions).
///
/// `start`/`end` are the half-open bar range for the whole day. `sess_start`/
/// `sess_end` are the half-open range of bars that fall *inside the configured
/// session*, and are what the backtest is allowed to touch.
///
/// Keeping these separate is the fix for the defect where the entry scan ran
/// from the end of the opening range to the end of the calendar day — on a
/// 24-hour instrument that is ~1,000 out-of-session bars, and a wick-break
/// entry among them fills at a stale opening-range level while exiting at the
/// current close, booking any overnight gap as profit.
#[derive(Clone, Debug)]
pub struct DayMeta {
    pub year: i32,
    /// year*10000 + month*100 + day, e.g. 20240315.
    pub date_code: u32,
    pub start: usize,
    pub end: usize,
    pub sess_start: usize,
    pub sess_end: usize,
}

impl DayMeta {
    /// Number of bars inside the session window.
    pub fn session_len(&self) -> usize {
        self.sess_end.saturating_sub(self.sess_start)
    }
}

/// A single simulated trade at one contract, before any position sizing.
#[derive(Clone, Copy, Debug)]
pub struct RawTrade {
    pub pnl_1ct: f64,
    /// Risk in points (the opening-range width), used for contract sizing.
    pub risk_1ct: f64,
    /// 0 = long, 1 = short.
    pub dir: u8,
    pub day_idx: usize,
}

/// Instrument contract specification plus the resolved session bounds.
#[derive(Clone, Debug)]
pub struct InstCfg {
    pub tick_size: f64,
    pub point_value: f64,
    pub open_mins: i32,
    pub close_mins: i32,
}

impl InstCfg {
    /// Largest opening range the engine will trade, in points.
    ///
    /// `backtest` rejects any day whose range is above `MAX_OR_TICKS`, so this
    /// is a hard bound — and with the exit rules it yields a hard per-trade
    /// profit ceiling, which `tests` asserts against.
    pub fn max_or_points(&self) -> f64 {
        MAX_OR_TICKS * self.tick_size
    }

    /// Round-turn cost at one contract for a given slippage allowance.
    pub fn round_turn_cost(&self, slippage_ticks: i32, commission: f64) -> f64 {
        slippage_ticks as f64 * self.tick_size * self.point_value * 2.0 + commission
    }

    /// The most a single trade can net at one contract, given the opening-range
    /// cap and the richest available target multiple.
    pub fn per_trade_ceiling(&self, max_target_mult: f64, slippage_ticks: i32, commission: f64) -> f64 {
        max_target_mult * self.max_or_points() * self.point_value
            - self.round_turn_cost(slippage_ticks, commission)
    }
}

/// Opening range must be at least this many ticks wide to be tradeable.
pub const MIN_OR_TICKS: f64 = 4.0;
/// ...and at most this many.
pub const MAX_OR_TICKS: f64 = 200.0;

pub fn get_inst(s: &str) -> InstCfg {
    match s {
        "ES" => InstCfg { tick_size: 0.25, point_value: 50.0, open_mins: 570, close_mins: 960 },
        "NQ" => InstCfg { tick_size: 0.25, point_value: 20.0, open_mins: 570, close_mins: 960 },
        "GC" => InstCfg { tick_size: 0.10, point_value: 100.0, open_mins: 570, close_mins: 960 },
        other => panic!("Unknown instrument '{other}'. Known: ES, NQ, GC."),
    }
}

// ---------------------------------------------------------------------------
// Strategy modes
// ---------------------------------------------------------------------------

#[derive(Clone, Copy, PartialEq, Eq, Debug, Hash)]
pub enum EntryMode {
    WickBreak,
    CloseCross,
}
#[derive(Clone, Copy, PartialEq, Eq, Debug, Hash)]
pub enum BiasMode {
    NoBias,
    DailyBias,
}
#[derive(Clone, Copy, PartialEq, Eq, Debug, Hash)]
pub enum ExitMode {
    FixedRR,
    LuxTargets,
}

impl EntryMode {
    pub fn as_str(&self) -> &'static str {
        match self {
            Self::WickBreak => "wick",
            Self::CloseCross => "close",
        }
    }
}
impl BiasMode {
    pub fn as_str(&self) -> &'static str {
        match self {
            Self::NoBias => "nobias",
            Self::DailyBias => "daily",
        }
    }
}
impl ExitMode {
    pub fn as_str(&self) -> &'static str {
        match self {
            Self::FixedRR => "fixedrr",
            Self::LuxTargets => "luxtgt",
        }
    }
}

pub fn all_variants() -> Vec<(EntryMode, BiasMode, ExitMode)> {
    let mut v = Vec::new();
    for &e in &[EntryMode::WickBreak, EntryMode::CloseCross] {
        for &b in &[BiasMode::NoBias, BiasMode::DailyBias] {
            for &x in &[ExitMode::FixedRR, ExitMode::LuxTargets] {
                v.push((e, b, x));
            }
        }
    }
    v
}

/// Parse `--entry-variants`. Unrecognised tokens abort rather than silently
/// falling back to the full grid.
pub fn parse_variants(s: &str) -> Vec<(EntryMode, BiasMode, ExitMode)> {
    let s = s.trim().to_lowercase();
    if s == "all" || s.is_empty() {
        return all_variants();
    }
    let all = all_variants();
    let mut out = Vec::new();
    let mut bad: Vec<String> = Vec::new();
    for tok in s.split(',') {
        let raw = tok.trim().to_string();
        let p: Vec<&str> = raw.split('/').collect();
        if p.len() != 3 {
            bad.push(raw);
            continue;
        }
        let em = match p[0] {
            "wick" => EntryMode::WickBreak,
            "close" => EntryMode::CloseCross,
            _ => { bad.push(raw); continue; }
        };
        let bm = match p[1] {
            "nobias" => BiasMode::NoBias,
            "daily" => BiasMode::DailyBias,
            _ => { bad.push(raw); continue; }
        };
        let xm = match p[2] {
            "fixedrr" => ExitMode::FixedRR,
            "luxtgt" => ExitMode::LuxTargets,
            _ => { bad.push(raw); continue; }
        };
        if all.contains(&(em, bm, xm)) {
            out.push((em, bm, xm));
        } else {
            bad.push(raw);
        }
    }
    if !bad.is_empty() {
        panic!(
            "--entry-variants: unrecognized token(s): {}. \
             Expected comma-separated <wick|close>/<nobias|daily>/<fixedrr|luxtgt> or 'all'.",
            bad.join(",")
        );
    }
    if out.is_empty() { all } else { out }
}

// ---------------------------------------------------------------------------
// Prop-firm account specification
// ---------------------------------------------------------------------------

/// One evaluation/funded account product.
///
/// Every field here feeds expected value directly. They are transcribed from
/// the firm's published terms and are NOT independently verified by this code —
/// check them against current terms before trusting any output.
#[derive(Clone, Debug)]
pub struct Apex {
    pub key: String,
    pub name: String,
    /// Evaluation profit target.
    pub target: f64,
    /// Evaluation trailing drawdown.
    pub dd: f64,
    /// Daily loss limit (0 = none).
    pub dll: f64,
    /// True if the trailing drawdown is evaluated end-of-day rather than intraday.
    pub is_eod: bool,
    /// Contract cap during the evaluation.
    pub eval_cts: i32,
    /// Evaluation purchase price — paid on every attempt, passed or failed.
    pub fee: f64,
    /// Funded contract cap after the safety net is reached.
    pub pa_max: i32,
    /// Funded contract cap before the safety net is reached.
    pub pa_start: i32,
    /// Safety-net balance. Reaching it converts the trailing drawdown into a
    /// fixed floor, which is the structural source of edge in this product.
    pub sn: f64,
    /// Funded starting balance.
    pub sb: f64,
    /// Funded trailing drawdown.
    pub pa_dd: f64,
    /// Withdrawal ladder — caps the n-th withdrawal.
    pub ladder: [f64; 6],
    /// Minimum daily profit for a day to count toward the qualifying-day tally.
    pub qmin: f64,
    /// Qualifying days required before a withdrawal.
    pub qdays: i32,
    /// Consistency rule: no single day may exceed this fraction of total profit.
    pub cons: f64,
    /// One-off activation fee — paid only on a funded account.
    pub act: f64,
    /// Monthly platform/account fee — paid only while funded.
    pub pamo: f64,
    /// Per-round-turn commission.
    pub comm: f64,
}

pub fn all_apex() -> Vec<Apex> {
    vec![
        Apex { key: "eod50".into(), name: "EOD 50K".into(), target: 3000.0, dd: 2500.0, dll: 1000.0, is_eod: true, eval_cts: 6, fee: 34.90,
               pa_max: 4, pa_start: 2, sn: 52600.0, sb: 50000.0, pa_dd: 2500.0, ladder: [1500.0, 1750.0, 2000.0, 2500.0, 2750.0, 3000.0],
               qmin: 300.0, qdays: 5, cons: 0.5, act: 99.0, pamo: 85.0, comm: 4.50 },
        Apex { key: "eod100".into(), name: "EOD 100K".into(), target: 6000.0, dd: 3000.0, dll: 2000.0, is_eod: true, eval_cts: 8, fee: 59.90,
               pa_max: 6, pa_start: 3, sn: 103100.0, sb: 100000.0, pa_dd: 3000.0, ladder: [2000.0, 2500.0, 3000.0, 3500.0, 3750.0, 4000.0],
               qmin: 300.0, qdays: 5, cons: 0.5, act: 99.0, pamo: 85.0, comm: 4.50 },
        Apex { key: "eod150".into(), name: "EOD 150K".into(), target: 9000.0, dd: 5000.0, dll: 2500.0, is_eod: true, eval_cts: 12, fee: 79.90,
               pa_max: 9, pa_start: 4, sn: 155100.0, sb: 150000.0, pa_dd: 5000.0, ladder: [2500.0, 3000.0, 3500.0, 4000.0, 4500.0, 5000.0],
               qmin: 350.0, qdays: 5, cons: 0.5, act: 99.0, pamo: 85.0, comm: 4.50 },
        Apex { key: "it50".into(), name: "IT 50K".into(), target: 3000.0, dd: 2500.0, dll: 0.0, is_eod: false, eval_cts: 6, fee: 24.90,
               pa_max: 4, pa_start: 2, sn: 52600.0, sb: 50000.0, pa_dd: 2500.0, ladder: [1500.0, 1750.0, 2000.0, 2500.0, 2750.0, 3000.0],
               qmin: 250.0, qdays: 5, cons: 0.5, act: 79.0, pamo: 85.0, comm: 4.50 },
        Apex { key: "it100".into(), name: "IT 100K".into(), target: 6000.0, dd: 3000.0, dll: 0.0, is_eod: false, eval_cts: 8, fee: 39.90,
               pa_max: 6, pa_start: 3, sn: 103100.0, sb: 100000.0, pa_dd: 3000.0, ladder: [2000.0, 2500.0, 3000.0, 3500.0, 3750.0, 4000.0],
               qmin: 300.0, qdays: 5, cons: 0.5, act: 79.0, pamo: 85.0, comm: 4.50 },
        Apex { key: "it150".into(), name: "IT 150K".into(), target: 9000.0, dd: 5000.0, dll: 0.0, is_eod: false, eval_cts: 12, fee: 59.90,
               pa_max: 9, pa_start: 4, sn: 155100.0, sb: 150000.0, pa_dd: 5000.0, ladder: [2500.0, 3000.0, 3500.0, 4000.0, 4500.0, 5000.0],
               qmin: 350.0, qdays: 5, cons: 0.5, act: 79.0, pamo: 85.0, comm: 4.50 },
    ]
}

// ---------------------------------------------------------------------------
// Risk geometries
// ---------------------------------------------------------------------------

pub const RG_NAMES: [&str; 7] = [
    "fixed_1", "floor_aware", "dd_frac_40", "cppi", "time_decay", "asymmetric", "five_gear",
];
pub const N_RISK_GEOS: usize = 7;

/// Contracts to trade for one signal under risk geometry `rg`.
///
/// `eq`/`pk` are equity and running peak relative to the account's own zero,
/// `mdd` the drawdown allowance, `max_c` the phase contract cap.
#[inline]
#[allow(clippy::too_many_arguments)]
pub fn calc_cts(
    rg: usize,
    eq: f64,
    pk: f64,
    mdd: f64,
    max_c: i32,
    day: i32,
    max_days: i32,
    target: f64,
    dir: u8,
    long_wr: f64,
    short_wr: f64,
    trade_risk_1ct: f64,
    pv: f64,
) -> i32 {
    let dr = mdd - (pk - eq);
    let dr = if dr <= 0.0 { 0.01 } else { dr };
    let dp = dr / mdd;
    let trd = trade_risk_1ct * pv;
    let raw = match rg {
        0 => 1,
        1 => {
            if dp > 0.7 { max_c } else if dp > 0.4 { (max_c / 2).max(1) } else { 1 }
        }
        2 => (dr * 0.40 / trd.max(0.01)) as i32,
        3 => {
            let floor = -(mdd * 0.9);
            let cushion = eq - floor;
            (2.0 * cushion / trd.max(0.01)) as i32
        }
        4 => {
            let tp = day as f64 / max_days.max(1) as f64;
            let prog = if target != 0.0 { eq / target } else { 0.0 };
            if tp > 0.7 && prog < 0.5 { max_c }
            else if tp > 0.7 && prog > 0.8 { 1 }
            else if tp < 0.3 { max_c }
            else { (max_c / 2).max(1) }
        }
        5 => {
            let wr = if dir == 0 { long_wr } else { short_wr };
            if wr > 0.55 { max_c } else if wr > 0.50 { (max_c * 2 / 3).max(1) } else { 1 }
        }
        6 => {
            if dp > 0.9 { max_c }
            else if dp > 0.7 { (max_c * 4 / 5).max(1) }
            else if dp > 0.5 { (max_c * 3 / 5).max(1) }
            else if dp > 0.3 { (max_c * 2 / 5).max(1) }
            else { 1 }
        }
        _ => 1,
    };
    raw.clamp(1, max_c.max(1))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn per_trade_ceiling_matches_hand_arithmetic() {
        // NQ: 200 ticks * 0.25 = 50.00 points; 1.5x target at $20/pt = $1,500;
        // round turn = 2 * 0.25 * 20 * 2 + 4.50 = $24.50.
        let nq = get_inst("NQ");
        assert_eq!(nq.max_or_points(), 50.0);
        assert_eq!(nq.round_turn_cost(2, 4.50), 24.50);
        assert_eq!(nq.per_trade_ceiling(1.5, 2, 4.50), 1475.50);

        // GC: 200 ticks * 0.10 = 20.00 points; 1.5x at $100/pt = $3,000;
        // round turn = 2 * 0.10 * 100 * 2 + 4.50 = $44.50.
        let gc = get_inst("GC");
        assert_eq!(gc.per_trade_ceiling(1.5, 2, 4.50), 2955.50);

        // ES
        let es = get_inst("ES");
        assert_eq!(es.per_trade_ceiling(1.5, 2, 4.50), 3695.50);
    }

    #[test]
    fn calc_cts_respects_phase_cap() {
        for rg in 0..N_RISK_GEOS {
            for &max_c in &[1, 4, 12] {
                let c = calc_cts(rg, 5000.0, 5000.0, 2500.0, max_c, 3, 30, 3000.0, 0, 0.9, 0.9, 10.0, 20.0);
                assert!(c >= 1 && c <= max_c, "rg={rg} max_c={max_c} gave {c}");
            }
        }
    }

    #[test]
    fn calc_cts_fixed_one_is_always_one() {
        for eq in [-2000.0, 0.0, 9000.0] {
            assert_eq!(calc_cts(0, eq, 9000.0, 2500.0, 12, 1, 30, 3000.0, 0, 0.9, 0.1, 5.0, 50.0), 1);
        }
    }

    #[test]
    fn parse_variants_rejects_typos() {
        assert_eq!(parse_variants("all").len(), 8);
        assert_eq!(parse_variants("wick/nobias/fixedrr").len(), 1);
        assert!(std::panic::catch_unwind(|| parse_variants("wick/nobais/fixedrr")).is_err());
    }

    #[test]
    fn apex_commission_is_wired_not_dead() {
        // Every account must carry a usable commission; the engine reads it
        // rather than hard-coding a constant.
        for a in all_apex() {
            assert!(a.comm > 0.0, "{} has no commission", a.key);
            assert!(a.act > 0.0 && a.pamo > 0.0, "{} missing funded-phase costs", a.key);
        }
    }
}
