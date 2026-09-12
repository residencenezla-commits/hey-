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
/// Every field feeds expected value directly. Values were checked against
/// published summaries in September 2026 (see `README.md` for sources and for
/// what could not be verified), but firms change terms frequently and both
/// Apex and Topstep run parallel legacy rule sets. Confirm against your own
/// account dashboard before trusting output.
#[derive(Clone, Debug)]
pub struct Account {
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

    /// Whether the consistency rule applies during the *evaluation*.
    ///
    /// Apex applies it only in the funded account; the previous model enforced
    /// it in both, which understated the pass rate. Topstep's combine target
    /// does behave as a constraint on the evaluation.
    pub eval_consistency: bool,
    /// Maximum *calendar* days allowed to complete the evaluation (0 = untimed).
    ///
    /// This is the trap in the old model: Apex allows 30 calendar days, which is
    /// about 21 trading days, but the simulator ran 30 *trading* days — roughly
    /// 40% more opportunity than the product allows.
    pub eval_calendar_days: i32,
    /// Lifetime profit per account paid at 100% before the split applies.
    pub split_full_up_to: f64,
    /// Trader's share of profit beyond `split_full_up_to`.
    pub split_after: f64,
}

/// Trading days per calendar month, used to convert a calendar-day evaluation
/// window into the number of sessions actually available.
pub const TRADING_DAYS_PER_CALENDAR_DAY: f64 = 21.0 / 30.44;

impl Account {
    /// Evaluation length in trading days, or `None` when untimed.
    pub fn eval_trading_days(&self) -> Option<usize> {
        if self.eval_calendar_days <= 0 {
            return None;
        }
        Some(((self.eval_calendar_days as f64) * TRADING_DAYS_PER_CALENDAR_DAY).round() as usize)
    }

    /// Trader's take on `gross` of extracted profit, applying the split.
    pub fn trader_share(&self, gross: f64) -> f64 {
        if gross <= self.split_full_up_to {
            gross
        } else {
            self.split_full_up_to + (gross - self.split_full_up_to) * self.split_after
        }
    }
}

/// Which firm's rule set to model.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Firm {
    /// Apex Trader Funding, "4.0" rules (accounts purchased from 1 March 2026).
    Apex,
    /// Topstep Trading Combine and Express Funded Account.
    Topstep,
}

impl Firm {
    pub fn parse(s: &str) -> Self {
        match s.trim().to_lowercase().as_str() {
            "apex" => Firm::Apex,
            "topstep" => Firm::Topstep,
            other => panic!("--firm must be apex or topstep, got '{other}'"),
        }
    }
    pub fn as_str(&self) -> &'static str {
        match self {
            Firm::Apex => "apex",
            Firm::Topstep => "topstep",
        }
    }
    pub fn accounts(&self) -> Vec<Account> {
        match self {
            Firm::Apex => apex_accounts(),
            Firm::Topstep => topstep_accounts(),
        }
    }
}

/// Apex Trader Funding, 4.0 rules.
///
/// Verified September 2026 against published summaries:
///   * Profit target is 6% of balance: $3,000 / $6,000 / $9,000.
///   * Safety net is balance + drawdown + $100, and on 4.0 accounts every
///     payout must clear it (legacy accounts only observed it for the first
///     three).
///   * Evaluation runs **30 calendar days**, roughly 21 trading sessions.
///   * The consistency rule applies only in the funded account, not the
///     evaluation, and 4.0 relaxed it from 30% to 50%.
///   * Five qualifying days per payout (4.0 reduced this from seven).
///   * Six payouts per account, $500 minimum, 100% of the first $25,000 per
///     account and 90% thereafter.
pub fn apex_accounts() -> Vec<Account> {
    let mk = |key: &str, name: &str, sb: f64, target: f64, dd: f64, dll: f64, is_eod: bool,
              eval_cts: i32, fee: f64, pa_start: i32, pa_max: i32, ladder: [f64; 6],
              qmin: f64, act: f64| Account {
        key: key.into(), name: name.into(),
        target, dd, dll, is_eod, eval_cts, fee,
        pa_start, pa_max,
        // Safety net = starting balance + drawdown + $100.
        sn: sb + dd + 100.0,
        sb, pa_dd: dd,
        ladder, qmin, qdays: 5, cons: 0.50,
        act, pamo: 85.0, comm: 4.50,
        eval_consistency: false,
        eval_calendar_days: 30,
        split_full_up_to: 25_000.0,
        split_after: 0.90,
    };
    vec![
        mk("eod50",  "Apex EOD 50K",  50_000.0, 3_000.0, 2_500.0, 1_000.0, true,  6, 34.90, 2, 4,
           [1500.0, 1750.0, 2000.0, 2500.0, 2750.0, 3000.0], 300.0, 99.0),
        mk("eod100", "Apex EOD 100K", 100_000.0, 6_000.0, 3_000.0, 2_000.0, true,  8, 59.90, 3, 6,
           [2000.0, 2500.0, 3000.0, 3500.0, 3750.0, 4000.0], 300.0, 99.0),
        mk("eod150", "Apex EOD 150K", 150_000.0, 9_000.0, 5_000.0, 2_500.0, true, 12, 79.90, 4, 9,
           [2500.0, 3000.0, 3500.0, 4000.0, 4500.0, 5000.0], 350.0, 99.0),
        mk("it50",   "Apex IT 50K",   50_000.0, 3_000.0, 2_500.0, 0.0, false,  6, 24.90, 2, 4,
           [1500.0, 1750.0, 2000.0, 2500.0, 2750.0, 3000.0], 250.0, 79.0),
        mk("it100",  "Apex IT 100K",  100_000.0, 6_000.0, 3_000.0, 0.0, false,  8, 39.90, 3, 6,
           [2000.0, 2500.0, 3000.0, 3500.0, 3750.0, 4000.0], 300.0, 79.0),
        mk("it150",  "Apex IT 150K",  150_000.0, 9_000.0, 5_000.0, 0.0, false, 12, 59.90, 4, 9,
           [2500.0, 3000.0, 3500.0, 4000.0, 4500.0, 5000.0], 350.0, 79.0),
    ]
}

/// Topstep Trading Combine and Express Funded Account.
///
/// Verified September 2026 against published summaries. Topstep is NOT a
/// re-parameterised Apex — three mechanics differ in kind, and two of them this
/// model does not reproduce:
///   * Maximum Loss Limit is $2,000 / $3,000 / $4,500, end-of-day trailing,
///     and it stops trailing once the account is $x above start.
///   * The Daily Loss Limit is an optional add-on ($1k/$2k/$3k) and hitting it
///     is NOT a rule violation in the Combine, so it is modelled as absent.
///   * The combine consistency target (best day <= 50% of the profit target)
///     RAISES the target rather than failing the account. Modelled here as a
///     pass condition, which is stricter than the real rule.
///   * Payouts take 90% from the first dollar on accounts opened after
///     12 January 2026, and are capped at 50% of balance up to a tier cap.
///     The tier caps are used as a flat ladder below; the 50%-of-balance
///     component is not modelled.
///   * Five winning days per payout on the standard path.
/// Position limits are in minis: 5 / 10 / 15.
pub fn topstep_accounts() -> Vec<Account> {
    let mk = |key: &str, name: &str, sb: f64, target: f64, mll: f64, cts: i32,
              fee: f64, act: f64, cap: f64, qmin: f64| Account {
        key: key.into(), name: name.into(),
        target, dd: mll, dll: 0.0, is_eod: true, eval_cts: cts, fee,
        pa_start: cts, pa_max: cts,
        // Topstep has no Apex-style safety net; the trailing stop freezes once
        // the account is the drawdown amount above its start.
        sn: sb + mll,
        sb, pa_dd: mll,
        ladder: [cap; 6],
        qmin, qdays: 5, cons: 0.50,
        act, pamo: 0.0, comm: 4.50,
        eval_consistency: true,
        eval_calendar_days: 0, // the Combine is untimed while the fee is paid
        split_full_up_to: 0.0,
        split_after: 0.90,
    };
    vec![
        mk("ts50",  "Topstep 50K",  50_000.0, 3_000.0, 2_000.0,  5, 49.0, 149.0, 2_000.0, 200.0),
        mk("ts100", "Topstep 100K", 100_000.0, 6_000.0, 3_000.0, 10, 99.0, 149.0, 4_000.0, 300.0),
        mk("ts150", "Topstep 150K", 150_000.0, 9_000.0, 4_500.0, 15, 199.0, 149.0, 6_000.0, 400.0),
    ]
}

/// Backwards-compatible alias for the Apex set.
pub fn all_apex() -> Vec<Account> {
    apex_accounts()
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


    /// The published account terms, pinned so an edit cannot drift from them
    /// silently. Checked September 2026; see README for sources.
    #[test]
    fn apex_terms_match_published_rules() {
        let a = apex_accounts();
        assert_eq!(a.len(), 6);
        for ax in &a {
            // Profit target is 6% of the starting balance.
            assert!((ax.target - ax.sb * 0.06).abs() < 1e-9, "{} target {}", ax.key, ax.target);
            // Safety net is balance + drawdown + $100.
            assert!((ax.sn - (ax.sb + ax.dd + 100.0)).abs() < 1e-9, "{} safety net {}", ax.key, ax.sn);
            // 4.0: five qualifying days, 50% consistency, six payouts, $85/month.
            assert_eq!(ax.qdays, 5, "{}", ax.key);
            assert!((ax.cons - 0.50).abs() < 1e-9, "{}", ax.key);
            assert_eq!(ax.ladder.len(), 6, "{}", ax.key);
            assert!((ax.pamo - 85.0).abs() < 1e-9, "{}", ax.key);
            // No consistency rule during the evaluation.
            assert!(!ax.eval_consistency, "{} must not gate the evaluation on consistency", ax.key);
            // 30 calendar days, which is about 21 sessions -- not 30 sessions.
            assert_eq!(ax.eval_calendar_days, 30, "{}", ax.key);
            assert_eq!(ax.eval_trading_days(), Some(21), "{}", ax.key);
            // 100% of the first $25,000 per account, 90% after.
            assert!((ax.split_full_up_to - 25_000.0).abs() < 1e-9, "{}", ax.key);
            assert!((ax.split_after - 0.90).abs() < 1e-9, "{}", ax.key);
            // Half contracts until the safety net.
            assert!(ax.pa_start < ax.pa_max, "{}", ax.key);
        }
        let named = |k: &str| a.iter().find(|x| x.key == k).unwrap().clone();
        assert_eq!(named("eod50").sn, 52_600.0);
        assert_eq!(named("eod100").sn, 103_100.0);
        assert_eq!(named("eod150").sn, 155_100.0);
        assert_eq!(named("it50").eval_cts, 6);
        assert_eq!(named("it100").eval_cts, 8);
        assert_eq!(named("it150").eval_cts, 12);
        // Intraday-trailing products carry no daily loss limit; EOD ones do.
        assert!(a.iter().filter(|x| !x.is_eod).all(|x| x.dll == 0.0));
        assert!(a.iter().filter(|x| x.is_eod).all(|x| x.dll > 0.0));
    }

    #[test]
    fn topstep_terms_match_published_rules() {
        let t = topstep_accounts();
        assert_eq!(t.len(), 3);
        let named = |k: &str| t.iter().find(|x| x.key == k).unwrap().clone();
        // Same profit targets as Apex, different maximum loss limits.
        for (k, sb, target, mll, cts) in [
            ("ts50", 50_000.0, 3_000.0, 2_000.0, 5),
            ("ts100", 100_000.0, 6_000.0, 3_000.0, 10),
            ("ts150", 150_000.0, 9_000.0, 4_500.0, 15),
        ] {
            let a = named(k);
            assert_eq!(a.sb, sb);
            assert_eq!(a.target, target);
            assert_eq!(a.dd, mll, "{k} maximum loss limit");
            assert_eq!(a.eval_cts, cts, "{k} position limit in minis");
            // The daily loss limit is an optional add-on and breaching it is not
            // a rule violation, so it is modelled as absent.
            assert_eq!(a.dll, 0.0, "{k}");
            // 90/10 from the first dollar.
            assert_eq!(a.split_full_up_to, 0.0, "{k}");
            assert!((a.split_after - 0.90).abs() < 1e-9, "{k}");
            // The combine is untimed while the subscription is paid.
            assert_eq!(a.eval_trading_days(), None, "{k}");
            // No monthly fee on the funded account; the cost is the combine.
            assert_eq!(a.pamo, 0.0, "{k}");
            assert_eq!(a.act, 149.0, "{k}");
        }
        assert_eq!(named("ts50").fee, 49.0);
        assert_eq!(named("ts100").fee, 99.0);
        assert_eq!(named("ts150").fee, 199.0);
    }

    /// Apex's evaluation runs 30 calendar days. Treating that as 30 trading days
    /// hands the simulator about 40% more sessions than the product allows.
    #[test]
    fn calendar_days_convert_to_fewer_trading_days() {
        let ax = apex_accounts()[0].clone();
        let sessions = ax.eval_trading_days().unwrap();
        assert_eq!(sessions, 21);
        assert!(
            (30 - sessions) as f64 / sessions as f64 > 0.4,
            "the old 30-trading-day window was {sessions} sessions too generous"
        );
    }

    #[test]
    fn profit_split_applies_beyond_the_full_share() {
        let apex = apex_accounts()[0].clone();
        assert_eq!(apex.trader_share(10_000.0), 10_000.0, "under the threshold, all of it");
        assert_eq!(apex.trader_share(25_000.0), 25_000.0);
        // $35k gross -> $25k + 90% of the next $10k.
        assert_eq!(apex.trader_share(35_000.0), 34_000.0);

        let ts = topstep_accounts()[0].clone();
        assert_eq!(ts.trader_share(10_000.0), 9_000.0, "Topstep splits from dollar one");
    }

    #[test]
    fn firm_selector_parses_and_rejects() {
        assert_eq!(Firm::parse("apex").accounts().len(), 6);
        assert_eq!(Firm::parse("Topstep").accounts().len(), 3);
        assert!(std::panic::catch_unwind(|| Firm::parse("ftmo")).is_err());
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
