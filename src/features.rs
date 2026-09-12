//! Day-level features: volatility regime, Hurst exponent, sample entropy, and
//! the extreme-value tail threshold used to cap catastrophic losses.

// A NaN-bearing threshold test is written as `!(x >= lo)` rather than `x < lo`
// on purpose: an unmeasurable feature must FAIL a filter, and every comparison
// against NaN is false, so the negated form is the one that rejects it.
#![allow(clippy::neg_cmp_op_on_partial_ord)]

use crate::session::in_session;
use crate::types::{Bar, DayMeta, InstCfg};

// ---------------------------------------------------------------------------
// Volatility regime
// ---------------------------------------------------------------------------

#[derive(Clone, Copy, Default, Debug)]
pub struct VolFeatures {
    pub rv: f64,
    pub atr14: f64,
    pub vix_proxy: f64,
}

/// Regime codes. 0 means "not classifiable yet" (insufficient lookback).
pub const REGIME_UNKNOWN: u8 = 0;
pub const REGIME_CALM: u8 = 1;
pub const REGIME_NORMAL: u8 = 2;
pub const REGIME_ELEVATED: u8 = 3;
pub const REGIME_STRESSED: u8 = 4;
pub const REGIME_CRISIS: u8 = 5;

pub const REGIME_LOOKBACK: usize = 252;

/// Realised volatility, ATR and a 30-day close-to-close vol proxy, each lagged
/// one day so that day `d` only ever sees information complete before its open.
pub fn compute_vol_features(bars: &[Bar], days: &[DayMeta], inst: &InstCfg) -> Vec<VolFeatures> {
    let n_days = days.len();
    let mut feats = vec![VolFeatures::default(); n_days];

    let mut day_high = vec![f64::NEG_INFINITY; n_days];
    let mut day_low = vec![f64::INFINITY; n_days];
    let mut day_close = vec![0.0f64; n_days];
    let mut day_rv = vec![0.0f64; n_days];

    for (d, day) in days.iter().enumerate() {
        let (s, e) = (day.sess_start, day.sess_end);
        if e <= s {
            continue;
        }
        let mut prev_close = 0.0f64;
        let mut sum_sq = 0.0f64;
        let mut n_rets = 0usize;
        for b in &bars[s..e] {
            if !in_session(b.time_mins, inst.open_mins, inst.close_mins) {
                continue;
            }
            if b.high > day_high[d] { day_high[d] = b.high; }
            if b.low < day_low[d] { day_low[d] = b.low; }
            day_close[d] = b.close;
            if prev_close > 0.0 && b.close > 0.0 {
                let r = (b.close / prev_close).ln();
                sum_sq += r * r;
                n_rets += 1;
            }
            prev_close = b.close;
        }
        if n_rets > 5 {
            day_rv[d] = sum_sq.sqrt() * 252.0_f64.sqrt();
        }
    }

    let mut day_tr = vec![0.0f64; n_days];
    for d in 0..n_days {
        if day_high[d].is_infinite() || day_low[d].is_infinite() {
            continue;
        }
        day_tr[d] = if d == 0 {
            day_high[d] - day_low[d]
        } else {
            let prev_c = day_close[d - 1];
            (day_high[d] - day_low[d])
                .max((day_high[d] - prev_c).abs())
                .max((day_low[d] - prev_c).abs())
        };
    }

    let mut day_atr14 = vec![0.0f64; n_days];
    let mut run = 0.0f64;
    for d in 0..n_days {
        run += day_tr[d];
        if d >= 14 {
            run -= day_tr[d - 14];
        }
        if d >= 13 {
            day_atr14[d] = run / 14.0;
        }
    }

    let mut day_log_ret = vec![0.0f64; n_days];
    for d in 1..n_days {
        if day_close[d - 1] > 0.0 && day_close[d] > 0.0 {
            day_log_ret[d] = (day_close[d] / day_close[d - 1]).ln();
        }
    }
    let mut day_vp = vec![0.0f64; n_days];
    for d in 30..n_days {
        let slice = &day_log_ret[(d - 29)..=d];
        let mean: f64 = slice.iter().sum::<f64>() / slice.len() as f64;
        let var: f64 = slice.iter().map(|x| (x - mean).powi(2)).sum::<f64>() / slice.len() as f64;
        day_vp[d] = var.sqrt() * 252.0_f64.sqrt();
    }

    for d in 1..n_days {
        feats[d] = VolFeatures { rv: day_rv[d - 1], atr14: day_atr14[d - 1], vix_proxy: day_vp[d - 1] };
    }
    feats
}

/// Fraction of `sample` strictly below `x`.
fn pct_rank(sorted: &[f64], x: f64) -> f64 {
    if sorted.is_empty() {
        return 0.5;
    }
    let below = sorted.partition_point(|&v| v < x);
    below as f64 / sorted.len() as f64
}

/// Scale-free composite volatility score for day `d`, using only `[d-252, d)`.
///
/// Averaging the three indicators' percentile ranks is not itself uniformly
/// distributed: when the indicators are less than perfectly correlated the mean
/// of three ranks concentrates around 0.5, so cutting it at fixed quintiles
/// piles most days into the middle bucket. This returns the raw composite; the
/// uniformity comes from ranking it a second time in `classify_all_regimes`.
fn composite_score(feats: &[VolFeatures], d: usize) -> f64 {
    if d < REGIME_LOOKBACK {
        return f64::NAN;
    }
    let lo = d - REGIME_LOOKBACK;
    let mut rvs = Vec::with_capacity(REGIME_LOOKBACK);
    let mut atrs = Vec::with_capacity(REGIME_LOOKBACK);
    let mut vps = Vec::with_capacity(REGIME_LOOKBACK);
    for f in &feats[lo..d] {
        if f.rv > 0.0 { rvs.push(f.rv); }
        if f.atr14 > 0.0 { atrs.push(f.atr14); }
        if f.vix_proxy > 0.0 { vps.push(f.vix_proxy); }
    }
    if rvs.len() < 50 || atrs.len() < 50 || vps.len() < 50 {
        return f64::NAN;
    }
    rvs.sort_by(|a, b| a.partial_cmp(b).unwrap());
    atrs.sort_by(|a, b| a.partial_cmp(b).unwrap());
    vps.sort_by(|a, b| a.partial_cmp(b).unwrap());

    let f = &feats[d];
    if !(f.rv > 0.0) || !(f.atr14 > 0.0) || !(f.vix_proxy > 0.0) {
        return f64::NAN;
    }
    (pct_rank(&rvs, f.rv) + pct_rank(&atrs, f.atr14) + pct_rank(&vps, f.vix_proxy)) / 3.0
}

fn quintile_to_regime(p: f64) -> u8 {
    if p < 0.20 { REGIME_CALM }
    else if p < 0.40 { REGIME_NORMAL }
    else if p < 0.60 { REGIME_ELEVATED }
    else if p < 0.80 { REGIME_STRESSED }
    else { REGIME_CRISIS }
}

/// Assign every day a volatility regime.
///
/// The previous classifier tested `n_high` to exhaustion before consulting
/// `n_low`, so a day reading high on one measure and low on the other two was
/// filed as "elevated". Across sixteen years that produced 0.8% calm days
/// against 5.7% crisis days out of a nominal tercile split, leaving the calm
/// bucket too small to fit anything on.
///
/// This version ranks each day's composite volatility score against the
/// composites of the preceding year and cuts at quintiles, so each regime
/// receives about a fifth of days whatever the correlation between the three
/// indicators — and every regime-conditioned configuration has a real sample
/// behind it. Two lookback windows are needed before the first classification.
pub fn classify_all_regimes(feats: &[VolFeatures]) -> Vec<u8> {
    let n = feats.len();
    let composite: Vec<f64> = (0..n).map(|d| composite_score(feats, d)).collect();
    let mut out = vec![REGIME_UNKNOWN; n];
    for d in (2 * REGIME_LOOKBACK)..n {
        if !composite[d].is_finite() {
            continue;
        }
        let mut hist: Vec<f64> = composite[(d - REGIME_LOOKBACK)..d]
            .iter()
            .copied()
            .filter(|x| x.is_finite())
            .collect();
        if hist.len() < 50 {
            continue;
        }
        hist.sort_by(|a, b| a.partial_cmp(b).unwrap());
        out[d] = quintile_to_regime(pct_rank(&hist, composite[d]));
    }
    out
}

pub fn day_eligible(day_regime: u8, config_regime: &str) -> bool {
    match config_regime {
        "all" => true,
        "calm" => day_regime == REGIME_CALM,
        "normal" => day_regime == REGIME_NORMAL,
        "elevated" => day_regime == REGIME_ELEVATED,
        "stressed" => day_regime == REGIME_STRESSED,
        "crisis" => day_regime == REGIME_CRISIS,
        "not_high" => matches!(day_regime, REGIME_CALM | REGIME_NORMAL | REGIME_ELEVATED),
        _ => true,
    }
}

// ---------------------------------------------------------------------------
// Hurst
// ---------------------------------------------------------------------------

/// Daily closes required before a Hurst estimate is attempted.
pub const HURST_WIN: usize = 100;

/// Classical rescaled-range Hurst estimator, OLS on log(R/S) against log(n).
///
/// Returns `NaN` when there is not enough data to estimate — deliberately, so
/// that a threshold comparison against an unknown value fails rather than
/// silently passing. The previous code returned a 0.5 sentinel, which cleared
/// every `h_min <= 0.50` filter for the first hundred days of every instrument
/// on a placeholder rather than a measurement.
///
/// Even at 100 observations R/S carries a small-sample bias of roughly +0.05
/// toward 0.55 on white noise, and a standard error wider than the 0.02 spacing
/// of a typical threshold sweep. `hurst_white_noise_spread` exists to measure
/// that directly.
pub fn compute_hurst(closes: &[f64]) -> f64 {
    let n = closes.len();
    if n < 20 {
        return f64::NAN;
    }
    let mut rets: Vec<f64> = Vec::with_capacity(n);
    for i in 1..n {
        if closes[i - 1] > 0.0 && closes[i] > 0.0 {
            rets.push((closes[i] / closes[i - 1]).ln());
        }
    }
    let nr = rets.len();
    if nr < 20 {
        return f64::NAN;
    }
    let sizes = [10usize, 20, 30, 50, 80];
    let mut pts: Vec<(f64, f64)> = Vec::new();
    for &sz in &sizes {
        if sz > nr {
            continue;
        }
        let nc = nr / sz;
        if nc == 0 {
            continue;
        }
        let mut rs = 0.0;
        let mut cnt = 0u32;
        for c in 0..nc {
            let chunk = &rets[c * sz..c * sz + sz];
            let m: f64 = chunk.iter().sum::<f64>() / sz as f64;
            let mut cum = Vec::with_capacity(sz);
            let mut r = 0.0;
            for &v in chunk {
                r += v - m;
                cum.push(r);
            }
            let rng = cum.iter().cloned().fold(f64::NEG_INFINITY, f64::max)
                - cum.iter().cloned().fold(f64::INFINITY, f64::min);
            let std = (chunk.iter().map(|v| (v - m).powi(2)).sum::<f64>() / sz as f64).sqrt();
            if std > 1e-10 {
                rs += rng / std;
                cnt += 1;
            }
        }
        if cnt > 0 {
            pts.push(((sz as f64).ln(), (rs / cnt as f64).ln()));
        }
    }
    if pts.len() < 2 {
        return f64::NAN;
    }
    let np = pts.len() as f64;
    let sx: f64 = pts.iter().map(|p| p.0).sum();
    let sy: f64 = pts.iter().map(|p| p.1).sum();
    let sxy: f64 = pts.iter().map(|p| p.0 * p.1).sum();
    let sx2: f64 = pts.iter().map(|p| p.0 * p.0).sum();
    let den = np * sx2 - sx * sx;
    if den.abs() < 1e-10 {
        return f64::NAN;
    }
    ((np * sxy - sx * sy) / den).clamp(0.0, 1.0)
}

/// Mean and standard deviation of `compute_hurst` over `trials` white-noise
/// series of `win` closes. Used to decide whether a threshold sweep is finer
/// than the estimator's own noise.
pub fn hurst_white_noise_spread(win: usize, trials: usize, seed: u64) -> (f64, f64) {
    use rand::{Rng, SeedableRng};
    let mut rng = rand::rngs::StdRng::seed_from_u64(seed);
    let mut vals = Vec::with_capacity(trials);
    for _ in 0..trials {
        let mut px = 100.0f64;
        let mut closes = Vec::with_capacity(win);
        for _ in 0..win {
            px *= (rng.gen::<f64>() - 0.5).mul_add(0.02, 1.0);
            closes.push(px);
        }
        let h = compute_hurst(&closes);
        if h.is_finite() {
            vals.push(h);
        }
    }
    if vals.is_empty() {
        return (f64::NAN, f64::NAN);
    }
    let mean = vals.iter().sum::<f64>() / vals.len() as f64;
    let sd = (vals.iter().map(|v| (v - mean).powi(2)).sum::<f64>() / vals.len() as f64).sqrt();
    (mean, sd)
}

/// Last in-session close of each day, or 0.0 where the day has no session bars.
pub fn precompute_daily_closes(bars: &[Bar], days: &[DayMeta], inst: &InstCfg) -> Vec<f64> {
    let mut out = vec![0.0f64; days.len()];
    for (d, day) in days.iter().enumerate() {
        for b in &bars[day.sess_start..day.sess_end] {
            if in_session(b.time_mins, inst.open_mins, inst.close_mins) {
                out[d] = b.close;
            }
        }
    }
    out
}

/// Hurst for each day computed from closes strictly before that day.
/// Days without enough history carry `NaN`, not a sentinel.
pub fn precompute_hurst_prev(day_close: &[f64]) -> Vec<f64> {
    let n = day_close.len();
    let mut out = vec![f64::NAN; n];
    for d in HURST_WIN..n {
        let window: Vec<f64> = day_close[(d - HURST_WIN)..d].iter().copied().filter(|&c| c > 0.0).collect();
        if window.len() >= 20 {
            out[d] = compute_hurst(&window);
        }
    }
    out
}

// ---------------------------------------------------------------------------
// Sample entropy
// ---------------------------------------------------------------------------

/// Sample entropy (Richman & Moorman 2000), m = 2.
///
/// Both the m and m+1 counts iterate over the same `n - m` template positions
/// so that A/B is a proper conditional probability.
pub fn compute_entropy(data: &[f64]) -> f64 {
    let n = data.len();
    let m = 2usize;
    if n < m + 2 {
        return f64::NAN;
    }
    let mean = data.iter().sum::<f64>() / n as f64;
    let std = (data.iter().map(|x| (x - mean).powi(2)).sum::<f64>() / n as f64).sqrt();
    let r = 0.2 * std;
    if r < 1e-10 {
        return f64::NAN;
    }
    let upper = n - m;
    let count = |tl: usize| -> usize {
        let mut c = 0usize;
        for i in 0..upper {
            for j in (i + 1)..upper {
                let mut ok = true;
                for k in 0..tl {
                    if (data[i + k] - data[j + k]).abs() > r {
                        ok = false;
                        break;
                    }
                }
                if ok {
                    c += 1;
                }
            }
        }
        c
    };
    let b = count(m);
    let a = count(m + 1);
    if b == 0 || a == 0 {
        f64::NAN
    } else {
        -(a as f64 / b as f64).ln()
    }
}

// ---------------------------------------------------------------------------
// Extreme value tail
// ---------------------------------------------------------------------------

/// 95th-percentile loss estimated by fitting a generalised Pareto distribution
/// to exceedances over the empirical 90th percentile, by method of moments.
///
/// For X ~ GPD(sigma, xi): E[X] = sigma/(1-xi), Var[X] = sigma^2/((1-xi)^2(1-2xi)),
/// so 1 - 2*xi = mean^2/var and sigma = mean*(1-xi).
///
/// Returns `f64::INFINITY` when there is too little data to fit, which disables
/// the tail cap rather than applying an arbitrary one.
pub fn evt_tail_threshold(losses_abs: &[f64]) -> f64 {
    if losses_abs.len() < 20 {
        return f64::INFINITY;
    }
    let mut s = losses_abs.to_vec();
    s.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let idx = ((s.len() as f64) * 0.9) as usize;
    let u = s[idx.min(s.len() - 1)];
    let exc: Vec<f64> = s.iter().filter(|&&x| x > u).map(|&x| x - u).collect();
    if exc.len() < 5 {
        return s.last().copied().unwrap_or(f64::INFINITY);
    }
    let mean = exc.iter().sum::<f64>() / exc.len() as f64;
    let var = exc.iter().map(|x| (x - mean).powi(2)).sum::<f64>() / exc.len() as f64;
    if mean <= 0.0 || var <= 0.0 {
        return u;
    }
    let xi = 0.5 * (1.0 - mean * mean / var);
    let sigma = mean * (1.0 - xi);
    if sigma <= 0.0 {
        return u;
    }
    let p: f64 = 0.05;
    let q = if xi.abs() < 0.001 {
        u - sigma * p.ln()
    } else {
        u + sigma / xi * (p.powf(-xi) - 1.0)
    };
    q.max(u)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ramp_feats(n: usize) -> Vec<VolFeatures> {
        // Volatility rising smoothly then falling, so every quintile is populated.
        (0..n)
            .map(|i| {
                let phase = (i as f64) * 0.017;
                let v = 0.10 + 0.08 * phase.sin() + 0.00002 * i as f64;
                VolFeatures { rv: v, atr14: v * 12.0, vix_proxy: v * 0.9 }
            })
            .collect()
    }

    fn assert_balanced(regimes: &[u8]) {
        let mut counts = [0usize; 6];
        for &r in regimes {
            counts[r as usize] += 1;
        }
        let classified: usize = counts[1..].iter().sum();
        assert!(classified > 1500, "expected most days classified, got {classified}");
        for (r, &c) in counts.iter().enumerate().skip(1) {
            let share = c as f64 / classified as f64;
            assert!(
                (0.13..0.28).contains(&share),
                "regime {r} holds {:.1}% of days; quintiles should be near 20% (counts {counts:?})",
                share * 100.0
            );
        }
    }

    /// Strongly correlated indicators, as the three real measures are: one
    /// common volatility factor plus a little idiosyncratic noise each.
    #[test]
    fn regime_quintiles_are_balanced_when_indicators_agree() {
        use rand::{Rng, SeedableRng};
        let mut rng = rand::rngs::StdRng::seed_from_u64(5);
        let feats: Vec<VolFeatures> = (0..3000)
            .map(|_| {
                let common = rng.gen::<f64>();
                let jitter = |r: &mut rand::rngs::StdRng| (r.gen::<f64>() - 0.5) * 0.06;
                VolFeatures {
                    rv: 0.05 + common * 0.20 + jitter(&mut rng) * 0.2,
                    atr14: 12.0 * (0.05 + common * 0.20) + jitter(&mut rng),
                    vix_proxy: 0.9 * (0.05 + common * 0.20) + jitter(&mut rng) * 0.2,
                }
            })
            .collect();
        assert_balanced(&classify_all_regimes(&feats));
    }

    /// Independent indicators: the case a fixed cut on the mean of three ranks
    /// gets wrong. That mean concentrates around 0.5, so on real data — where
    /// the three measures are correlated but far from identical — it piled
    /// nearly half of all days into the middle bucket.
    #[test]
    fn regime_quintiles_are_balanced_when_indicators_are_independent() {
        use rand::{Rng, SeedableRng};
        let mut rng = rand::rngs::StdRng::seed_from_u64(21);
        let feats: Vec<VolFeatures> = (0..3000)
            .map(|_| VolFeatures {
                rv: 0.05 + rng.gen::<f64>() * 0.2,
                atr14: 1.0 + rng.gen::<f64>() * 20.0,
                vix_proxy: 0.05 + rng.gen::<f64>() * 0.3,
            })
            .collect();
        assert_balanced(&classify_all_regimes(&feats));
    }

    #[test]
    fn regime_needs_two_lookback_windows() {
        let r = classify_all_regimes(&ramp_feats(800));
        assert!(r[..2 * REGIME_LOOKBACK].iter().all(|&x| x == REGIME_UNKNOWN));
        assert!(r[2 * REGIME_LOOKBACK..].iter().any(|&x| x != REGIME_UNKNOWN));
    }

    #[test]
    fn unknown_regime_only_trades_under_all() {
        assert!(day_eligible(REGIME_UNKNOWN, "all"));
        for r in ["calm", "normal", "elevated", "stressed", "crisis", "not_high"] {
            assert!(!day_eligible(REGIME_UNKNOWN, r), "unknown regime leaked into {r}");
        }
    }

    #[test]
    fn hurst_is_nan_when_it_cannot_be_estimated() {
        assert!(compute_hurst(&[]).is_nan());
        assert!(compute_hurst(&[100.0; 5]).is_nan());
        // A NaN estimate must fail a threshold test rather than pass it.
        let h = compute_hurst(&[100.0; 5]);
        assert!(!(h >= 0.48), "NaN Hurst must not clear an h_min filter");
    }

    #[test]
    fn hurst_prev_uses_no_sentinel() {
        let closes: Vec<f64> = (0..300).map(|i| 100.0 + (i as f64) * 0.1).collect();
        let hp = precompute_hurst_prev(&closes);
        assert!(hp[..HURST_WIN].iter().all(|h| h.is_nan()), "early days must be NaN, not 0.5");
        assert!(hp[HURST_WIN..].iter().any(|h| h.is_finite()));
    }

    /// The estimator's own spread has to be wider than the sweep spacing for a
    /// threshold grid to mean anything. This records the measurement.
    #[test]
    fn hurst_on_white_noise_reports_its_own_spread() {
        let (mean, sd) = hurst_white_noise_spread(HURST_WIN, 300, 7);
        assert!(mean.is_finite() && sd.is_finite());
        assert!((0.35..=0.75).contains(&mean), "white-noise Hurst mean {mean:.3} is implausible");
        // Not an assertion about what it should be — a record of what it is, so
        // a 0.02-spaced threshold sweep can be judged against it.
        println!("white-noise Hurst over {HURST_WIN} closes: mean {mean:.4}, sd {sd:.4}");
        assert!(sd > 0.0);
    }

    #[test]
    fn entropy_is_nan_on_degenerate_input() {
        assert!(compute_entropy(&[]).is_nan());
        assert!(compute_entropy(&[1.0; 20]).is_nan(), "zero-variance input has no entropy");
        // A NaN entropy must not clear an e_max filter.
        let e = compute_entropy(&[1.0; 20]);
        assert!(!(e <= 1.5), "NaN entropy must not clear an e_max filter");
    }

    #[test]
    fn entropy_orders_regular_below_random() {
        use rand::{Rng, SeedableRng};
        let mut rng = rand::rngs::StdRng::seed_from_u64(11);
        let periodic: Vec<f64> = (0..120).map(|i| ((i as f64) * 0.5).sin()).collect();
        let noise: Vec<f64> = (0..120).map(|_| rng.gen::<f64>() - 0.5).collect();
        let ep = compute_entropy(&periodic);
        let en = compute_entropy(&noise);
        assert!(ep.is_finite() && en.is_finite());
        assert!(ep < en, "periodic entropy {ep:.3} should be below noise entropy {en:.3}");
    }

    #[test]
    fn evt_threshold_exceeds_the_body_of_the_loss_distribution() {
        use rand::{Rng, SeedableRng};
        let mut rng = rand::rngs::StdRng::seed_from_u64(3);
        let losses: Vec<f64> = (0..400).map(|_| -(1.0 - rng.gen::<f64>()).ln() * 100.0).collect();
        let t = evt_tail_threshold(&losses);
        let mut s = losses.clone();
        s.sort_by(|a, b| a.partial_cmp(b).unwrap());
        let p90 = s[(s.len() as f64 * 0.9) as usize];
        assert!(t >= p90, "tail threshold {t:.1} below the 90th percentile {p90:.1}");
        assert!(t.is_finite());
    }

    #[test]
    fn evt_threshold_disabled_when_undersampled() {
        assert!(evt_tail_threshold(&[1.0, 2.0, 3.0]).is_infinite());
        // An infinite threshold must never trigger a tail kill.
        assert!(!(500.0f64 > evt_tail_threshold(&[1.0, 2.0, 3.0])));
    }
}
