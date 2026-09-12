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
    /// Price of a single evaluation.
    pub fee_pack1: f64,
    /// Per-evaluation price when bought as a five-pack. Apex discounts these
    /// materially, which matters directly: at a 25% pass rate you expect four
    /// attempts per funded account.
    pub fee_pack5: f64,
    /// True when every field was read off the firm's own pricing page.
    /// False means the values are inferred and the engine warns before using them.
    pub verified: bool,
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

    /// Cheapest way to buy `n` expected evaluation attempts.
    ///
    /// Singles, or whole five-packs, whichever costs less. A five-pack is only
    /// worth it once the expected attempt count justifies the unused seats.
    pub fn cost_for_attempts(&self, n: f64) -> f64 {
        if !n.is_finite() || n <= 0.0 {
            return 0.0;
        }
        let singles = n * self.fee_pack1;
        let packs = (n / 5.0).ceil() * 5.0 * self.fee_pack5;
        singles.min(packs)
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

/// Apex Trader Funding.
///
/// The 50K rules and all four 50K price points were read directly off the
/// funding-path page in September 2026 and are marked `verified`. Every 50K
/// variant shares: 1 minimum day to pass, 6 mini / 60 micro contracts, a $3,000
/// profit target, a **$2,000** maximum drawdown, and an evaluation that is a
/// one-time purchase active for 30 days with no rebill and no resets.
///
/// Intraday Trail carries no daily loss limit; EOD Trail carries $1,000.
///
/// The 100K and 150K entries are NOT verified — their drawdowns in particular
/// do not follow from the 50K figure, and the funded-account rules (safety net,
/// activation fee, monthly fee, payout ladder, PA drawdown and contract limits)
/// were not visible on the pages captured. The engine warns before using them.
pub fn apex_accounts() -> Vec<Account> {
    // (key, name, is_eod, no_activation_path, fee1, fee5)
    let variants: [(&str, bool, bool, f64, f64); 4] = [
        ("it_std",     false, false, 24.90, 19.00),
        ("eod_std",    true,  false, 55.00, 49.00),
        ("it_noact",   false, true,  49.00, 49.00),
        ("eod_noact",  true,  true, 119.00, 109.00),
    ];
    // (size key, starting balance, target, eval drawdown, daily loss limit,
    //  contracts, ladder, qualifying-day minimum, verified)
    let sizes: [(&str, f64, f64, f64, f64, i32, [f64; 6], f64, bool); 3] = [
        ("50",  50_000.0,  3_000.0, 2_000.0, 1_000.0,  6,
         [1500.0, 1750.0, 2000.0, 2500.0, 2750.0, 3000.0], 300.0, true),
        ("100", 100_000.0, 6_000.0, 3_000.0, 2_000.0,  8,
         [2000.0, 2500.0, 3000.0, 3500.0, 3750.0, 4000.0], 300.0, false),
        ("150", 150_000.0, 9_000.0, 5_000.0, 2_500.0, 12,
         [2500.0, 3000.0, 3500.0, 4000.0, 4500.0, 5000.0], 350.0, false),
    ];

    let mut out = Vec::new();
    for (size_key, sb, target, dd, dll, cts, ladder, qmin, size_verified) in sizes {
        for (vkey, is_eod, no_act, fee1, fee5) in variants {
            // Scale factor purely for the price of the larger sizes, which was
            // not captured. Marked unverified along with the rest of the size.
            let scale = sb / 50_000.0;
            let (f1, f5) = if size_key == "50" { (fee1, fee5) } else { (fee1 * scale, fee5 * scale) };
            out.push(Account {
                key: format!("apex_{size_key}_{vkey}"),
                name: format!("Apex {size_key}K {}", if is_eod { "EOD" } else { "Intraday" }),
                target,
                dd,
                dll: if is_eod { dll } else { 0.0 },
                is_eod,
                eval_cts: cts,
                fee: f1,
                fee_pack1: f1,
                fee_pack5: f5,
                // Half contracts until the safety net; "Scaling: Built-in for PA".
                pa_start: (cts / 2).max(1),
                pa_max: cts,
                // UNVERIFIED: the funded-account safety net was not on the page.
                sn: sb + dd + 100.0,
                sb,
                pa_dd: dd,
                ladder,
                qmin,
                qdays: 5,
                cons: 0.50,
                // UNVERIFIED: the Standard path carries a PA activation fee whose
                // amount was not shown; the No Activation Fee path has none.
                act: if no_act { 0.0 } else { 99.0 },
                pamo: 85.0,
                comm: 4.50,
                // Apex applies no consistency rule during the evaluation.
                eval_consistency: false,
                // One-time purchase, active 30 days, expires, no resets.
                eval_calendar_days: 30,
                split_full_up_to: 25_000.0,
                split_after: 0.90,
                verified: size_verified,
            });
        }
    }
    out
}

/// Topstep Trading Combine and Express Funded Account.
///
/// Read off the pricing page in September 2026. Two dimensions are real
/// purchase decisions and both are modelled as separate accounts:
///
///   * **Fee path.** Standard is $49/$99/$199 a month with a $149 Express
///     Funded activation fee; No Activation Fee is $95/$149/$229 a month with
///     none. Which wins depends on pass rate and how long the funded account
///     is held, so the engine is left to decide rather than told.
///   * **Responsible Trading Advantage.** Opting in adds a daily loss limit of
///     $1,000/$2,000/$3,000 and DOUBLES the payout caps. That is a genuine
///     trade for this objective: a daily limit suppresses the variance that
///     helps during a capped-downside evaluation, and doubles what can be
///     extracted afterwards.
///
/// Shared: profit target $3,000/$6,000/$9,000, consistency target **55%**,
/// Max Loss Limit (the "One Rule") $2,000/$3,000/$4,500 end-of-day trailing,
/// contracts 5/10/15 mini. The monthly fee recurs, unlike Apex's one-time
/// evaluation purchase.
///
/// Not modelled: the daily loss limit is not an account-closing violation, and
/// the consistency target raises the profit target rather than failing the
/// account — both are treated here as hard constraints, which is stricter than
/// the real product. Payout caps are 50% of balance up to the tier cap; only
/// the tier cap is used.
pub fn topstep_accounts() -> Vec<Account> {
    // (size, balance, target, max loss limit, contracts, std fee, noact fee,
    //  daily loss limit under RTA, base payout cap, qualifying-day minimum)
    let sizes: [(&str, f64, f64, f64, i32, f64, f64, f64, f64, f64); 3] = [
        ("50",  50_000.0,  3_000.0, 2_000.0,  5,  49.0,  95.0, 1_000.0, 2_000.0, 200.0),
        ("100", 100_000.0, 6_000.0, 3_000.0, 10,  99.0, 149.0, 2_000.0, 4_000.0, 300.0),
        ("150", 150_000.0, 9_000.0, 4_500.0, 15, 199.0, 229.0, 3_000.0, 6_000.0, 400.0),
    ];
    let mut out = Vec::new();
    for (sk, sb, target, mll, cts, fee_std, fee_noact, rta_dll, cap, qmin) in sizes {
        for no_act in [false, true] {
            for rta in [false, true] {
                let fee = if no_act { fee_noact } else { fee_std };
                let payout_cap = if rta { cap * 2.0 } else { cap };
                out.push(Account {
                    key: format!(
                        "ts_{sk}_{}{}",
                        if no_act { "noact" } else { "std" },
                        if rta { "_rta" } else { "" }
                    ),
                    name: format!("Topstep {sk}K{}", if rta { " +RTA" } else { "" }),
                    target,
                    dd: mll,
                    dll: if rta { rta_dll } else { 0.0 },
                    is_eod: true,
                    eval_cts: cts,
                    fee,
                    fee_pack1: fee,
                    fee_pack5: fee,
                    pa_start: cts,
                    pa_max: cts,
                    // The trailing stop freezes once the account is the maximum
                    // loss limit above its starting balance.
                    sn: sb + mll,
                    sb,
                    pa_dd: mll,
                    ladder: [payout_cap; 6],
                    qmin,
                    qdays: 5,
                    cons: 0.55,
                    act: if no_act { 0.0 } else { 149.0 },
                    // The combine fee recurs monthly while the account is open.
                    pamo: fee,
                    comm: 4.50,
                    eval_consistency: true,
                    eval_calendar_days: 0, // untimed while the subscription is paid
                    split_full_up_to: 0.0,
                    split_after: 0.90,
                    verified: true,
                });
            }
        }
    }
    out
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


    /// Apex 50K, read directly off the funding-path page. Pinned so an edit
    /// cannot drift from the screenshots.
    #[test]
    fn apex_50k_matches_the_funding_page() {
        let a = apex_accounts();
        let g = |k: &str| a.iter().find(|x| x.key == k).unwrap().clone();

        for k in ["apex_50_it_std", "apex_50_eod_std", "apex_50_it_noact", "apex_50_eod_noact"] {
            let x = g(k);
            assert!(x.verified, "{k}");
            assert_eq!(x.sb, 50_000.0, "{k}");
            assert_eq!(x.target, 3_000.0, "{k}");
            // The page says $2,000, not the 6%-of-balance figure the target uses.
            assert_eq!(x.dd, 2_000.0, "{k} maximum drawdown");
            assert_eq!(x.eval_cts, 6, "{k} contracts");
            // One-time purchase, active 30 days, expires, no resets.
            assert_eq!(x.eval_calendar_days, 30, "{k}");
            assert_eq!(x.eval_trading_days(), Some(21), "{k}");
            // No consistency rule during the evaluation.
            assert!(!x.eval_consistency, "{k}");
        }

        // Intraday Trail carries no daily loss limit; EOD Trail carries $1,000.
        assert_eq!(g("apex_50_it_std").dll, 0.0);
        assert_eq!(g("apex_50_it_noact").dll, 0.0);
        assert_eq!(g("apex_50_eod_std").dll, 1_000.0);
        assert_eq!(g("apex_50_eod_noact").dll, 1_000.0);

        // The four price points, coupon prices, single and five-pack.
        for (k, one, five) in [
            ("apex_50_it_std", 24.90, 19.00),
            ("apex_50_eod_std", 55.00, 49.00),
            ("apex_50_it_noact", 49.00, 49.00),
            ("apex_50_eod_noact", 119.00, 109.00),
        ] {
            let x = g(k);
            assert!((x.fee_pack1 - one).abs() < 1e-9, "{k} single price {}", x.fee_pack1);
            assert!((x.fee_pack5 - five).abs() < 1e-9, "{k} pack price {}", x.fee_pack5);
        }

        // Only the No Activation Fee path escapes the activation charge.
        assert_eq!(g("apex_50_it_noact").act, 0.0);
        assert_eq!(g("apex_50_eod_noact").act, 0.0);
        assert!(g("apex_50_it_std").act > 0.0);
    }

    /// The larger sizes were not captured and must stay flagged.
    #[test]
    fn apex_larger_sizes_are_marked_inferred() {
        let a = apex_accounts();
        assert!(a.iter().filter(|x| x.sb == 50_000.0).all(|x| x.verified));
        assert!(a.iter().filter(|x| x.sb > 50_000.0).all(|x| !x.verified),
                "100K and 150K terms were not on the captured pages");
    }

    /// Five-packs are cheaper per seat, so a repeated player pays the pack price.
    #[test]
    fn pack_pricing_beats_singles_once_retries_are_expected() {
        let x = apex_accounts().into_iter().find(|a| a.key == "apex_50_it_std").unwrap();
        // One attempt: a five-pack wastes four seats.
        assert!((x.cost_for_attempts(1.0) - 24.90).abs() < 1e-9);
        // Five attempts: the pack wins.
        assert!((x.cost_for_attempts(5.0) - 95.00).abs() < 1e-9);
        assert!(x.cost_for_attempts(5.0) < 5.0 * x.fee_pack1);
        // Four attempts, which is what a 25% pass rate implies.
        assert!(x.cost_for_attempts(4.0) <= 4.0 * x.fee_pack1);
        assert_eq!(x.cost_for_attempts(0.0), 0.0);
        assert_eq!(x.cost_for_attempts(f64::INFINITY), 0.0);
    }

    #[test]
    fn topstep_matches_the_pricing_page() {
        let t = topstep_accounts();
        let g = |k: &str| t.iter().find(|x| x.key == k).unwrap().clone();
        assert_eq!(t.len(), 12, "3 sizes x 2 fee paths x 2 RTA states");

        for x in &t {
            // The consistency target is 55%, not 50%.
            assert!((x.cons - 0.55).abs() < 1e-9, "{} consistency", x.key);
            assert!(x.verified, "{}", x.key);
            // The combine is untimed while the subscription is paid.
            assert_eq!(x.eval_trading_days(), None, "{}", x.key);
            // 90/10 from the first dollar.
            assert_eq!(x.split_full_up_to, 0.0, "{}", x.key);
        }

        // Max Loss Limit, the "One Rule", and mini contract limits.
        for (k, mll, cts) in [("ts_50_std", 2_000.0, 5), ("ts_100_std", 3_000.0, 10), ("ts_150_std", 4_500.0, 15)] {
            assert_eq!(g(k).dd, mll, "{k}");
            assert_eq!(g(k).eval_cts, cts, "{k}");
        }

        // Standard is cheaper monthly but carries the $149 activation.
        assert_eq!(g("ts_50_std").fee, 49.0);
        assert_eq!(g("ts_50_std").act, 149.0);
        assert_eq!(g("ts_50_noact").fee, 95.0);
        assert_eq!(g("ts_50_noact").act, 0.0);
        assert_eq!(g("ts_150_std").fee, 199.0);
        assert_eq!(g("ts_150_noact").fee, 229.0);

        // Responsible Trading Advantage: accept a daily loss limit, get double
        // payout caps.
        assert_eq!(g("ts_50_std").dll, 0.0);
        assert_eq!(g("ts_50_std_rta").dll, 1_000.0);
        assert_eq!(g("ts_100_std_rta").dll, 2_000.0);
        assert_eq!(g("ts_150_std_rta").dll, 3_000.0);
        assert_eq!(g("ts_50_std_rta").ladder[0], 2.0 * g("ts_50_std").ladder[0]);
    }

    #[test]
    fn calendar_days_convert_to_fewer_trading_days() {
        let ax = apex_accounts()[0].clone();
        assert_eq!(ax.eval_trading_days(), Some(21));
        assert!(
            (30 - 21) as f64 / 21.0 > 0.4,
            "the old 30-trading-day window was far too generous against a 30-calendar-day rule"
        );
    }

    #[test]
    fn profit_split_applies_beyond_the_full_share() {
        let apex = apex_accounts()[0].clone();
        assert_eq!(apex.trader_share(10_000.0), 10_000.0);
        assert_eq!(apex.trader_share(25_000.0), 25_000.0);
        assert_eq!(apex.trader_share(35_000.0), 34_000.0);
        let ts = topstep_accounts()[0].clone();
        assert_eq!(ts.trader_share(10_000.0), 9_000.0, "Topstep splits from dollar one");
    }

    #[test]
    fn firm_selector_parses_and_rejects() {
        assert_eq!(Firm::parse("apex").accounts().len(), 12);
        assert_eq!(Firm::parse("Topstep").accounts().len(), 12);
        assert!(std::panic::catch_unwind(|| Firm::parse("ftmo")).is_err());
    }

    #[test]
    fn commission_is_wired_not_dead() {
        // Every account must carry a usable commission; the engine reads it
        // rather than hard-coding a constant.
        for a in all_apex().into_iter().chain(topstep_accounts()) {
            assert!(a.comm > 0.0, "{} has no commission", a.key);
            assert!(a.fee_pack1 > 0.0, "{} has no evaluation price", a.key);
            assert!(a.act >= 0.0, "{}", a.key);
            // An account with no activation fee must be paying for it elsewhere:
            // a higher evaluation price, or a recurring monthly charge.
            if a.act == 0.0 {
                assert!(a.fee_pack1 > 0.0 || a.pamo > 0.0, "{} has no costs at all", a.key);
            }
        }
    }
}
