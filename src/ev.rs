//! Expected value of an evaluation attempt.
//!
//! The previous model was `pr * mean_ext - (fee + act + pamo * 2)`. It charged
//! the activation fee and two months of platform fees on every attempt, passed
//! or failed — but both are payable only on a funded account — and it used two
//! months against a funded simulation that runs far longer.
//!
//! Subtracting the correct expression from that one leaves an error that depends
//! only on the pass rate:
//!
//! ```text
//! error(pr) = act * (pr - 1) + pamo * (pr * M - 2)
//! ```
//!
//! The evaluation fee cancels exactly — it is the one cost the old formula got
//! right. For the published account terms this changes sign near a 43% pass
//! rate, which sits inside the range these strategies actually produce, so no
//! constant correction recovers it.

use crate::types::Apex;

#[derive(Clone, Copy, Debug)]
pub struct EvParams {
    /// Fraction of simulated extraction assumed to be actually received.
    /// 1.0 assumes every payout is paid in full and on time.
    pub payout_haircut: f64,
    /// Number of accounts intended to be run on this configuration at once.
    pub parallel_accounts: usize,
}

impl Default for EvParams {
    fn default() -> Self {
        EvParams { payout_haircut: 1.0, parallel_accounts: 1 }
    }
}

#[derive(Clone, Copy, Debug, Default)]
pub struct EvBreakdown {
    pub pass_rate: f64,
    pub gross_ext: f64,
    /// Extraction after the realisation haircut.
    pub net_ext: f64,
    pub months_held: f64,
    /// Activation plus platform fees, incurred only when funded.
    pub funded_costs: f64,
    /// Evaluation purchase price, incurred on every attempt.
    pub eval_fee: f64,
    /// The ranking metric: value of buying one evaluation.
    pub ev_per_attempt: f64,
    /// Value per funded account obtained, including the cost of failed attempts.
    /// NaN when the pass rate is zero.
    pub ev_per_funded: f64,
    /// Expected evaluations purchased per funded account, 1/pr.
    pub expected_attempts: f64,
    /// What the superseded formula would have reported.
    pub legacy_ev: f64,
    /// legacy_ev - ev_per_attempt.
    pub legacy_error: f64,

    // Running several accounts on one configuration.
    pub n_parallel: usize,
    /// Total outlay exposed before any payout arrives.
    pub bankroll_at_risk: f64,
    pub ev_total: f64,
    /// Probability the whole venture extracts nothing. Identical to the single
    /// account figure, because accounts on the same configuration share one
    /// price path and therefore do not diversify.
    pub p_total_zero: f64,
}

/// Months of platform fee the old formula assumed.
const LEGACY_MONTHS: f64 = 2.0;

/// Compute expected value for one configuration on one account product.
pub fn evaluate(
    ax: &Apex,
    pass_rate: f64,
    gross_ext: f64,
    p_ext_zero: f64,
    months_held: f64,
    p: &EvParams,
) -> EvBreakdown {
    let haircut = p.payout_haircut.clamp(0.0, 1.0);
    let net_ext = gross_ext * haircut;
    let months = months_held.max(1.0);
    let funded_costs = ax.act + ax.pamo * months;

    let ev_per_attempt = pass_rate * (net_ext - funded_costs) - ax.fee;
    let expected_attempts = if pass_rate > 0.0 { 1.0 / pass_rate } else { f64::INFINITY };
    let ev_per_funded = if pass_rate > 0.0 {
        net_ext - funded_costs - ax.fee / pass_rate
    } else {
        f64::NAN
    };

    let legacy_ev = pass_rate * gross_ext - (ax.fee + ax.act + ax.pamo * LEGACY_MONTHS);

    let n = p.parallel_accounts.max(1);
    EvBreakdown {
        pass_rate,
        gross_ext,
        net_ext,
        months_held: months,
        funded_costs,
        eval_fee: ax.fee,
        ev_per_attempt,
        ev_per_funded,
        expected_attempts,
        legacy_ev,
        legacy_error: legacy_ev - ev_per_attempt,
        n_parallel: n,
        // Every account pays its fee up front; a funded one also pays activation.
        bankroll_at_risk: n as f64 * (ax.fee + pass_rate * ax.act),
        ev_total: n as f64 * ev_per_attempt,
        p_total_zero: p_ext_zero,
    }
}

/// Closed-form error of the superseded cost term, for a given account and
/// holding period. Exposed so the correction can be checked against the
/// published account constants rather than inferred from output.
pub fn legacy_cost_error(ax: &Apex, pass_rate: f64, months: f64) -> f64 {
    ax.act * (pass_rate - 1.0) + ax.pamo * (pass_rate * months - LEGACY_MONTHS)
}

/// Pass rate at which the superseded formula changes from understating expected
/// value to overstating it.
pub fn legacy_error_crossover(ax: &Apex, months: f64) -> f64 {
    (ax.act + ax.pamo * LEGACY_MONTHS) / (ax.act + ax.pamo * months)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::types::all_apex;

    fn acct(key: &str) -> Apex {
        all_apex().into_iter().find(|a| a.key == key).unwrap()
    }

    /// The correction must equal the closed-form error exactly, at every pass
    /// rate and on every account product.
    #[test]
    fn legacy_error_matches_closed_form() {
        let p = EvParams::default();
        for ax in all_apex() {
            for &pr in &[0.0, 0.05, 0.25, 0.4228, 0.4417, 0.6, 0.9, 1.0] {
                for &months in &[1.0, 2.0, 6.0] {
                    let b = evaluate(&ax, pr, 8000.0, 0.3, months, &p);
                    let expect = legacy_cost_error(&ax, pr, months);
                    assert!(
                        (b.legacy_error - expect).abs() < 1e-9,
                        "{} pr={pr} M={months}: {} vs {}",
                        ax.key,
                        b.legacy_error,
                        expect
                    );
                }
            }
        }
    }

    /// The evaluation fee cancels, so all six products collapse onto two curves.
    #[test]
    fn evaluation_fee_cancels_from_the_error() {
        let m = 6.0;
        let eod: Vec<f64> = ["eod50", "eod100", "eod150"]
            .iter()
            .map(|k| legacy_cost_error(&acct(k), 0.25, m))
            .collect();
        let it: Vec<f64> = ["it50", "it100", "it150"]
            .iter()
            .map(|k| legacy_cost_error(&acct(k), 0.25, m))
            .collect();
        assert!(eod.windows(2).all(|w| (w[0] - w[1]).abs() < 1e-9), "{eod:?}");
        assert!(it.windows(2).all(|w| (w[0] - w[1]).abs() < 1e-9), "{it:?}");
        assert!((eod[0] - it[0]).abs() > 1.0, "EOD and IT should differ by their activation fee");
    }

    /// The published numbers quoted in the review.
    #[test]
    fn crossover_and_endpoints_are_as_reported() {
        let m = 6.0;
        let eod = acct("eod50");
        let it = acct("it50");
        assert!((legacy_error_crossover(&eod, m) - 0.4417).abs() < 5e-4);
        assert!((legacy_error_crossover(&it, m) - 0.4228).abs() < 5e-4);
        assert!((legacy_cost_error(&eod, 0.0, m) - (-269.0)).abs() < 1e-9);
        assert!((legacy_cost_error(&it, 0.0, m) - (-249.0)).abs() < 1e-9);
        assert!((legacy_cost_error(&eod, 1.0, m) - 340.0).abs() < 1e-9);
        assert!((legacy_cost_error(&it, 1.0, m) - 340.0).abs() < 1e-9);
        // Sign really does change inside the observed 5.4%-64.2% band.
        assert!(legacy_cost_error(&eod, 0.054, m) < 0.0);
        assert!(legacy_cost_error(&eod, 0.642, m) > 0.0);
    }

    /// A configuration that never passes is worth exactly minus the fee — no
    /// activation or platform fees are incurred on an account never funded.
    #[test]
    fn a_failing_config_costs_exactly_one_fee() {
        let ax = acct("eod150");
        let b = evaluate(&ax, 0.0, 0.0, 1.0, 6.0, &EvParams::default());
        assert!((b.ev_per_attempt + ax.fee).abs() < 1e-9, "got {}", b.ev_per_attempt);
        assert!(b.ev_per_funded.is_nan());
        assert!(b.expected_attempts.is_infinite());
    }

    #[test]
    fn ev_per_funded_accounts_for_failed_attempts() {
        let ax = acct("it100");
        let b = evaluate(&ax, 0.25, 9000.0, 0.2, 6.0, &EvParams::default());
        // Four attempts expected per funded account.
        assert!((b.expected_attempts - 4.0).abs() < 1e-9);
        let expect = 9000.0 - (ax.act + ax.pamo * 6.0) - ax.fee / 0.25;
        assert!((b.ev_per_funded - expect).abs() < 1e-9);
        // And the per-attempt figure is consistent with it.
        assert!((b.ev_per_attempt - 0.25 * b.ev_per_funded).abs() < 1e-9);
    }

    #[test]
    fn payout_haircut_reduces_value_but_not_cost() {
        let ax = acct("eod100");
        let full = evaluate(&ax, 0.5, 10_000.0, 0.2, 6.0, &EvParams::default());
        let cut = evaluate(
            &ax, 0.5, 10_000.0, 0.2, 6.0,
            &EvParams { payout_haircut: 0.8, parallel_accounts: 1 },
        );
        assert!((cut.net_ext - 8000.0).abs() < 1e-9);
        assert!((full.ev_per_attempt - cut.ev_per_attempt - 0.5 * 2000.0).abs() < 1e-9);
        assert_eq!(full.funded_costs, cut.funded_costs);
    }

    /// Running the same configuration on several accounts multiplies value and
    /// outlay, and does not reduce the chance of extracting nothing.
    #[test]
    fn parallel_accounts_do_not_diversify() {
        let ax = acct("it50");
        let one = evaluate(&ax, 0.4, 6000.0, 0.35, 6.0, &EvParams::default());
        let twenty = evaluate(
            &ax, 0.4, 6000.0, 0.35, 6.0,
            &EvParams { payout_haircut: 1.0, parallel_accounts: 20 },
        );
        assert!((twenty.ev_total - 20.0 * one.ev_per_attempt).abs() < 1e-9);
        assert!((twenty.bankroll_at_risk - 20.0 * one.bankroll_at_risk).abs() < 1e-9);
        assert_eq!(
            twenty.p_total_zero, one.p_total_zero,
            "accounts sharing one price path share one outcome; risk of zero must not fall"
        );
    }

    #[test]
    fn longer_holding_period_costs_more() {
        let ax = acct("eod50");
        let short = evaluate(&ax, 0.5, 9000.0, 0.2, 2.0, &EvParams::default());
        let long = evaluate(&ax, 0.5, 9000.0, 0.2, 6.0, &EvParams::default());
        assert!(long.funded_costs > short.funded_costs);
        assert!(long.ev_per_attempt < short.ev_per_attempt);
        assert!((long.funded_costs - short.funded_costs - 4.0 * ax.pamo).abs() < 1e-9);
    }
}
