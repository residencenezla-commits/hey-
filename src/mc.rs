//! Monte Carlo simulation of the evaluation and funded phases.
//!
//! This is the part of the engine that produces the objective: the probability
//! of passing an evaluation, and the distribution of what a funded account
//! extracts. Every defect here propagates linearly into expected value, so the
//! corrections below are the ones that matter most:
//!
//! * A tail-capped loss no longer bypasses the intraday drawdown check. It
//!   previously broke out of the trade loop before that check ran, which meant
//!   the single largest loss of the day was the one loss that could not blow an
//!   intraday-trailing account.
//! * The daily loss limit can be treated as a rule breach rather than a free
//!   stop-loss. Truncating the day and continuing understates failure on every
//!   account that carries one.
//! * The evaluation length is a parameter. It was hard-coded to 30 trading days,
//!   discarding every path that would have passed later — which penalises
//!   low-variance configurations specifically.

use crate::types::{calc_cts, Account, RawTrade};
use rand::rngs::StdRng;
use rand::{Rng, SeedableRng};
use rayon::prelude::*;

/// Trading days per calendar month, for converting a simulated funded duration
/// into the number of monthly fees actually incurred.
pub const TRADING_DAYS_PER_MONTH: f64 = 21.0;

#[derive(Clone, Debug)]
pub struct McConfig {
    pub n_challenge_sims: usize,
    pub n_funded_sims: usize,
    /// Maximum trading days allowed to reach the evaluation target.
    pub eval_max_days: usize,
    /// Maximum trading days simulated in the funded phase.
    pub funded_max_days: usize,
    pub block_days: usize,
    /// True if hitting the daily loss limit fails the account outright.
    pub dll_is_breach: bool,
}

impl McConfig {
    pub fn validate(&self) {
        assert!(self.block_days >= 1, "--block-days must be at least 1 (0 would never terminate)");
        assert!(self.eval_max_days >= 1, "--eval-max-days must be at least 1");
        assert!(self.funded_max_days >= 1, "--funded-max-days must be at least 1");
        assert!(self.n_challenge_sims >= 1, "--n-sims must be at least 1");
    }
}

/// Circular block bootstrap of day indices.
fn draw_days(rng: &mut StdRng, n_days: usize, block: usize, want: usize) -> Vec<usize> {
    debug_assert!(block >= 1 && n_days >= 1);
    let mut idx = Vec::with_capacity(want);
    while idx.len() < want {
        let start = rng.gen_range(0..n_days);
        for k in 0..block {
            if idx.len() >= want {
                break;
            }
            idx.push((start + k) % n_days);
        }
    }
    idx
}

#[derive(Clone, Copy, Debug, Default)]
pub struct ChallengeResult {
    pub pass_rate: f64,
    /// Mean trading days to pass, over passing simulations only.
    pub avg_days_to_pass: f64,
}

/// Simulate the evaluation phase.
#[allow(clippy::too_many_arguments)]
pub fn mc_challenge(
    train_by_day: &[Vec<RawTrade>],
    ax: &Account,
    rg: usize,
    cfg: &McConfig,
    pv: f64,
    long_wr: f64,
    short_wr: f64,
    evt_kill: f64,
    base_seed: u64,
) -> ChallengeResult {
    if train_by_day.is_empty() {
        return ChallengeResult::default();
    }
    let n_days = train_by_day.len();
    let max_days = cfg.eval_max_days;

    let res: Vec<(bool, u32)> = (0..cfg.n_challenge_sims)
        .into_par_iter()
        .map(|sim| {
            let seed = base_seed
                ^ (sim as u64).wrapping_mul(0x9E37_79B9_7F4A_7C15)
                ^ 0x4348_414C_4C55; // "CHALL"
            let mut rng = StdRng::seed_from_u64(seed);
            let day_idx = draw_days(&mut rng, n_days, cfg.block_days, max_days);

            let mut eq = 0.0f64;
            let mut pk = 0.0f64;
            let mut dp: Vec<f64> = Vec::with_capacity(max_days);

            for day in 0..max_days {
                let mut dpnl = 0.0f64;
                let mut blown = false;

                for t in &train_by_day[day_idx[day]] {
                    let cts = calc_cts(
                        rg, eq, pk, ax.dd, ax.eval_cts, day as i32, max_days as i32, ax.target,
                        t.dir, long_wr, short_wr, t.risk_1ct, pv,
                    );
                    let mut tail_killed = false;
                    let mut trade_pnl = t.pnl_1ct * cts as f64;
                    if t.pnl_1ct < 0.0 && t.pnl_1ct.abs() > evt_kill {
                        trade_pnl = -evt_kill * cts as f64;
                        tail_killed = true;
                    }
                    dpnl += trade_pnl;

                    // The intraday drawdown test runs for this trade as well —
                    // including a tail-capped one, which used to break out first.
                    if !ax.is_eod {
                        let ie = eq + dpnl;
                        if ie > pk {
                            pk = ie;
                        }
                        if ie < pk - ax.dd {
                            blown = true;
                            break;
                        }
                    }
                    if ax.dll > 0.0 && dpnl <= -ax.dll {
                        if cfg.dll_is_breach {
                            blown = true;
                        } else {
                            dpnl = -ax.dll;
                        }
                        break;
                    }
                    if tail_killed {
                        break;
                    }
                }
                if blown {
                    return (false, 0);
                }

                dp.push(dpnl);
                eq += dpnl;
                if eq > pk {
                    pk = eq;
                }
                if eq < pk - ax.dd {
                    return (false, 0);
                }

                if eq >= ax.target {
                    // Apex applies no consistency rule during the evaluation;
                    // Topstep's combine target does constrain it.
                    if !ax.eval_consistency {
                        return (true, (day + 1) as u32);
                    }
                    let sum: f64 = dp.iter().sum();
                    let mx = dp.iter().cloned().fold(f64::NEG_INFINITY, f64::max);
                    if sum > 0.0 && mx / sum <= ax.cons {
                        return (true, (day + 1) as u32);
                    }
                }
            }
            (false, 0)
        })
        .collect();

    let n_pass = res.iter().filter(|r| r.0).count();
    let days: u64 = res.iter().filter(|r| r.0).map(|r| r.1 as u64).sum();
    ChallengeResult {
        pass_rate: n_pass as f64 / cfg.n_challenge_sims as f64,
        avg_days_to_pass: if n_pass > 0 { days as f64 / n_pass as f64 } else { 0.0 },
    }
}

#[derive(Clone, Copy, Default, Debug)]
pub struct FundedDist {
    pub mean_ext: f64,
    pub median_ext: f64,
    pub p5_ext: f64,
    pub p_ext_zero: f64,
    pub p_ext_2500: f64,
    pub p_ext_10k: f64,
    pub p_ext_20k: f64,
    pub blowup_rate: f64,
    pub max_pa_dd_median: f64,
    pub max_pa_dd_p95: f64,
    /// Mean trading days the funded account was held, which sets how many
    /// monthly fees are actually paid.
    pub mean_days_held: f64,
}

impl FundedDist {
    /// Months of platform fee implied by the simulated holding period.
    pub fn months_held(&self) -> f64 {
        (self.mean_days_held / TRADING_DAYS_PER_MONTH).max(1.0)
    }
}

/// Simulate the funded phase: reach the safety net, then extract on the ladder.
#[allow(clippy::too_many_arguments)]
pub fn mc_funded(
    train_by_day: &[Vec<RawTrade>],
    ax: &Account,
    rg: usize,
    cfg: &McConfig,
    pv: f64,
    long_wr: f64,
    short_wr: f64,
    evt_kill: f64,
    base_seed: u64,
) -> FundedDist {
    if train_by_day.is_empty() || cfg.n_funded_sims == 0 {
        return FundedDist::default();
    }
    let n_days = train_by_day.len();
    let max_days = cfg.funded_max_days;

    let res: Vec<(f64, bool, f64, u32)> = (0..cfg.n_funded_sims)
        .into_par_iter()
        .map(|sim| {
            let seed = base_seed
                ^ (sim as u64).wrapping_mul(0x9E37_79B9_7F4A_7C15)
                ^ 0x4655_4E44_4544; // "FUNDED"
            let mut rng = StdRng::seed_from_u64(seed);
            let day_idx = draw_days(&mut rng, n_days, cfg.block_days, max_days);

            let mut bal = ax.sb;
            let mut pk = ax.sb;
            let mut ext = 0.0f64;
            let mut step = 0usize;
            let mut qd = 0i32;
            let mut since_payout: Vec<f64> = Vec::with_capacity(max_days);
            let mut max_dd_seen = 0.0f64;
            let mut blown = false;
            let mut sn_locked = false;
            let lock_floor = ax.sb + 100.0;
            let mut days_held = 0u32;

            for day in 0..max_days {
                if step >= ax.ladder.len() {
                    break;
                }
                days_held = (day + 1) as u32;
                let mxc = if sn_locked { ax.pa_max } else { ax.pa_start };
                let mut dpnl = 0.0f64;

                for t in &train_by_day[day_idx[day]] {
                    // Sizing always reads the live peak: a frozen peak makes
                    // (pk - eq) negative and inflates every cushion geometry.
                    let virtual_eq = bal - ax.sb;
                    let virtual_pk = pk - ax.sb;
                    let cts = calc_cts(
                        rg, virtual_eq, virtual_pk, ax.pa_dd, mxc, day as i32, max_days as i32,
                        ax.pa_dd, t.dir, long_wr, short_wr, t.risk_1ct, pv,
                    );
                    let mut tail_killed = false;
                    let mut trade_pnl = t.pnl_1ct * cts as f64;
                    if t.pnl_1ct < 0.0 && t.pnl_1ct.abs() > evt_kill {
                        trade_pnl = -evt_kill * cts as f64;
                        tail_killed = true;
                    }
                    dpnl += trade_pnl;

                    let floor_now = if sn_locked { lock_floor } else { pk - ax.pa_dd };
                    if bal + dpnl < floor_now {
                        blown = true;
                        break;
                    }
                    if ax.dll > 0.0 && dpnl <= -ax.dll {
                        if cfg.dll_is_breach {
                            blown = true;
                        } else {
                            dpnl = -ax.dll;
                        }
                        break;
                    }
                    if tail_killed {
                        break;
                    }
                }

                bal += dpnl;
                if bal > pk {
                    pk = bal;
                }
                if !sn_locked && (pk >= ax.sn || bal >= ax.sn) {
                    sn_locked = true;
                }
                let floor = if sn_locked { lock_floor } else { pk - ax.pa_dd };
                let cur_dd = pk - bal;
                if cur_dd > max_dd_seen {
                    max_dd_seen = cur_dd;
                }
                if blown || bal < floor {
                    blown = true;
                    break;
                }

                since_payout.push(dpnl);
                if dpnl >= ax.qmin {
                    qd += 1;
                }

                if qd >= ax.qdays && step < ax.ladder.len() && bal > ax.sn {
                    let total: f64 = since_payout.iter().sum();
                    let biggest = since_payout.iter().cloned().fold(f64::NEG_INFINITY, f64::max);
                    // The consistency rule only permits a payout when profit is
                    // positive and no single day dominates it.
                    let consistent = total > 0.0 && biggest / total <= ax.cons;
                    if consistent {
                        let w = (bal - ax.sn).min(ax.ladder[step]);
                        if w >= 500.0 {
                            bal -= w;
                            ext += w;
                            step += 1;
                            qd = 0;
                            since_payout.clear();
                        }
                    }
                }
            }
            (ext, blown, max_dd_seen, days_held)
        })
        .collect();

    let n = res.len() as f64;
    let mut ext_sorted: Vec<f64> = res.iter().map(|r| r.0).collect();
    ext_sorted.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let mut dd_sorted: Vec<f64> = res.iter().map(|r| r.2).collect();
    dd_sorted.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let q = |v: &[f64], p: f64| -> f64 {
        if v.is_empty() {
            return 0.0;
        }
        let idx = ((v.len() as f64 - 1.0) * p).round() as usize;
        v[idx.min(v.len() - 1)]
    };

    FundedDist {
        mean_ext: res.iter().map(|r| r.0).sum::<f64>() / n,
        median_ext: q(&ext_sorted, 0.50),
        p5_ext: q(&ext_sorted, 0.05),
        p_ext_zero: res.iter().filter(|r| r.0 <= 0.0).count() as f64 / n,
        p_ext_2500: res.iter().filter(|r| r.0 >= 2500.0).count() as f64 / n,
        p_ext_10k: res.iter().filter(|r| r.0 >= 10_000.0).count() as f64 / n,
        p_ext_20k: res.iter().filter(|r| r.0 >= 20_000.0).count() as f64 / n,
        blowup_rate: res.iter().filter(|r| r.1).count() as f64 / n,
        max_pa_dd_median: q(&dd_sorted, 0.50),
        max_pa_dd_p95: q(&dd_sorted, 0.95),
        mean_days_held: res.iter().map(|r| r.3 as f64).sum::<f64>() / n,
    }
}

/// Deterministic seed for one grid cell.
///
/// Every field that distinguishes one configuration from another must be mixed
/// in. An under-specified seed gives distinct configurations an identical
/// bootstrap index sequence, which correlates their pass-rate estimates — and
/// the neighbour-stability and worst-fold gates compare exactly those estimates.
#[allow(clippy::too_many_arguments)]
pub fn config_seed(base: u64, parts: &[&str], nums: &[f64], ints: &[i64]) -> u64 {
    const PRIME: u64 = 0x0000_0100_0000_01B3;
    let mut s = base;
    for p in parts {
        for byte in p.as_bytes() {
            s = s.wrapping_mul(PRIME).wrapping_add(*byte as u64);
        }
        s = s.wrapping_mul(PRIME).wrapping_add(0x5F);
    }
    for &v in nums {
        s = s.wrapping_mul(PRIME).wrapping_add((v * 1000.0).round() as i64 as u64);
    }
    for &v in ints {
        s = s.wrapping_mul(PRIME).wrapping_add(v as u64);
    }
    s
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::types::all_apex;

    fn cfg() -> McConfig {
        McConfig {
            n_challenge_sims: 400,
            n_funded_sims: 200,
            eval_max_days: 30,
            funded_max_days: 120,
            block_days: 5,
            dll_is_breach: true,
        }
    }
    fn acct(key: &str) -> Account {
        all_apex().into_iter().find(|a| a.key == key).unwrap()
    }
    fn days_of(pnl: f64, n: usize) -> Vec<Vec<RawTrade>> {
        (0..n)
            .map(|d| vec![RawTrade { pnl_1ct: pnl, risk_1ct: 10.0, dir: 0, day_idx: d }])
            .collect()
    }

    #[test]
    fn block_days_zero_is_rejected_not_hung() {
        let mut c = cfg();
        c.block_days = 0;
        assert!(std::panic::catch_unwind(move || c.validate()).is_err());
    }

    #[test]
    fn draw_days_terminates_and_fills() {
        let mut rng = StdRng::seed_from_u64(1);
        for block in [1usize, 5, 7] {
            for want in [1usize, 30, 120] {
                let v = draw_days(&mut rng, 9, block, want);
                assert_eq!(v.len(), want);
                assert!(v.iter().all(|&i| i < 9));
            }
        }
    }

    /// An intraday-trailing account that breaches its drawdown must be recorded
    /// as blown, including when the breach comes from a tail-capped loss.
    #[test]
    fn intraday_drawdown_breach_is_detected() {
        let ax = acct("apex_50_it_std"); // is_eod = false, dd = 2500, no daily loss limit
        // One trade a day, each losing more than the whole drawdown allowance.
        let losers = days_of(-4000.0, 8);
        let r = mc_challenge(&losers, &ax, 0, &cfg(), 20.0, 0.5, 0.5, f64::INFINITY, 7);
        assert_eq!(r.pass_rate, 0.0, "an account losing 4000 a day cannot pass a 2500 drawdown");

        // Now with the tail cap active and set below the drawdown: the capped
        // loss still accumulates and must still be able to blow the account.
        let r2 = mc_challenge(&losers, &ax, 0, &cfg(), 20.0, 0.5, 0.5, 900.0, 7);
        assert_eq!(r2.pass_rate, 0.0, "tail-capped losses must not bypass the drawdown check");
    }

    /// With the daily loss limit treated as a breach, a strategy that trips it
    /// every day cannot pass; treated as a cap, it survives to trade again.
    #[test]
    fn daily_loss_limit_as_breach_is_stricter_than_as_cap() {
        let ax = acct("apex_50_eod_std"); // dll = 1000, dd = 2500, target = 3000
        let mut sequence = days_of(-1200.0, 6); // trips the 1000 limit every day
        sequence.push(vec![RawTrade { pnl_1ct: 4000.0, risk_1ct: 10.0, dir: 0, day_idx: 6 }]);

        let mut breach = cfg();
        breach.dll_is_breach = true;
        let mut cap = cfg();
        cap.dll_is_breach = false;

        let pr_breach = mc_challenge(&sequence, &ax, 0, &breach, 20.0, 0.5, 0.5, f64::INFINITY, 3).pass_rate;
        let pr_cap = mc_challenge(&sequence, &ax, 0, &cap, 20.0, 0.5, 0.5, f64::INFINITY, 3).pass_rate;
        assert!(
            pr_breach <= pr_cap,
            "treating the daily limit as a breach cannot be more permissive: {pr_breach} vs {pr_cap}"
        );
    }

    /// A longer evaluation window cannot lower the pass rate: the same paths are
    /// still available, plus later ones. The old hard-coded 30 discarded those.
    #[test]
    fn longer_evaluation_window_never_lowers_pass_rate() {
        let ax = acct("apex_50_eod_std");
        // Small positive drift: needs many days to reach a 3000 target.
        let grind = days_of(120.0, 40);
        let mut short = cfg();
        short.eval_max_days = 20;
        let mut long = cfg();
        long.eval_max_days = 60;
        let pr_short = mc_challenge(&grind, &ax, 0, &short, 20.0, 0.5, 0.5, f64::INFINITY, 5).pass_rate;
        let pr_long = mc_challenge(&grind, &ax, 0, &long, 20.0, 0.5, 0.5, f64::INFINITY, 5).pass_rate;
        assert!(pr_long >= pr_short, "60-day window {pr_long} below 20-day {pr_short}");
        assert!(pr_long > 0.0, "a steady grinder must eventually pass a longer evaluation");
    }

    #[test]
    fn same_seed_reproduces_exactly() {
        let ax = acct("apex_150_it_std");
        let d = days_of(250.0, 12);
        let a = mc_challenge(&d, &ax, 2, &cfg(), 20.0, 0.5, 0.5, f64::INFINITY, 42);
        let b = mc_challenge(&d, &ax, 2, &cfg(), 20.0, 0.5, 0.5, f64::INFINITY, 42);
        assert_eq!(a.pass_rate, b.pass_rate);
        assert_eq!(a.avg_days_to_pass, b.avg_days_to_pass);
        let f1 = mc_funded(&d, &ax, 2, &cfg(), 20.0, 0.5, 0.5, f64::INFINITY, 42);
        let f2 = mc_funded(&d, &ax, 2, &cfg(), 20.0, 0.5, 0.5, f64::INFINITY, 42);
        assert_eq!(f1.mean_ext, f2.mean_ext);
        assert_eq!(f1.blowup_rate, f2.blowup_rate);
    }

    /// Configurations differing only in a field the old seed omitted must get
    /// different bootstrap draws.
    #[test]
    fn seed_distinguishes_every_config_axis() {
        let base = 42u64;
        let mut seen = std::collections::HashSet::new();
        for inst in ["ES", "NQ"] {
            for variant in ["wick/nobias/fixedrr", "close/daily/luxtgt"] {
                for fmode in ["none", "hurst", "both"] {
                    for regime in ["all", "calm", "crisis"] {
                        for lux in [0.5f64, 1.0, 1.5] {
                            let s = config_seed(base, &[inst, variant, fmode, regime], &[lux, 1.0, 0.5, 0.0], &[30, 2024, 0, 3]);
                            assert!(seen.insert(s), "seed collision on {inst}/{variant}/{fmode}/{regime}/{lux}");
                        }
                    }
                }
            }
        }
        assert_eq!(seen.len(), 2 * 2 * 3 * 3 * 3);
    }

    #[test]
    fn funded_reports_holding_period_for_the_fee_model() {
        let ax = acct("apex_150_it_std");
        let d = days_of(400.0, 20);
        let f = mc_funded(&d, &ax, 0, &cfg(), 20.0, 0.5, 0.5, f64::INFINITY, 9);
        assert!(f.mean_days_held > 0.0);
        assert!(f.months_held() >= 1.0);
        assert!(
            f.mean_days_held <= cfg().funded_max_days as f64,
            "held {} days beyond the {} day horizon",
            f.mean_days_held,
            cfg().funded_max_days
        );
    }

    #[test]
    fn a_losing_strategy_extracts_nothing_and_blows_up() {
        let ax = acct("apex_50_it_std");
        let d = days_of(-300.0, 15);
        let f = mc_funded(&d, &ax, 0, &cfg(), 20.0, 0.5, 0.5, f64::INFINITY, 4);
        assert_eq!(f.mean_ext, 0.0);
        assert!(f.blowup_rate > 0.9, "blowup rate {} too low for a pure loser", f.blowup_rate);
    }
}
