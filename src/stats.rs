//! Realised statistics for a set of one-contract trades.

use crate::types::RawTrade;
use std::collections::HashSet;

/// Trading days per calendar month, for normalising a period return.
pub const TRADING_DAYS_PER_MONTH: f64 = 21.0;

#[derive(Clone, Debug, Default)]
pub struct RealizedStats {
    /// Monthly expectancy at one contract: total profit over the period,
    /// normalised by the length of the period.
    ///
    /// The previous definition was `mean_trade_pnl * 30`, which assumes the
    /// configuration trades once every calendar day. A configuration firing
    /// twice a year had its per-trade mean extrapolated roughly a hundredfold,
    /// and since that figure carried most of the ranking weight the whole result
    /// set was sorted toward the smallest samples in the grid.
    pub monthly_ev_1ct: f64,
    pub total_pnl_1ct: f64,
    pub avg_trade_pnl: f64,
    pub n_trades: usize,
    pub n_days_traded: usize,
    pub period_days: usize,
    pub win_rate: f64,
    /// `None` when there were no losing trades, so that "undefined" is not
    /// averaged in as if it were an ordinary large value.
    pub profit_factor: Option<f64>,
    pub max_dd_1ct: f64,
    pub best_trade: f64,
    pub worst_trade: f64,
}

pub fn realized_stats(trades: &[RawTrade], period_days: usize) -> RealizedStats {
    if trades.is_empty() || period_days == 0 {
        return RealizedStats { period_days, ..Default::default() };
    }
    let n = trades.len();
    let pnls: Vec<f64> = trades.iter().map(|t| t.pnl_1ct).collect();
    let total: f64 = pnls.iter().sum();
    let mean = total / n as f64;
    let wins = pnls.iter().filter(|&&p| p > 0.0).count();
    let gross_win: f64 = pnls.iter().filter(|&&p| p > 0.0).sum();
    let gross_loss: f64 = pnls.iter().filter(|&&p| p < 0.0).map(|p| p.abs()).sum();

    let mut peak = 0.0f64;
    let mut cum = 0.0f64;
    let mut max_dd = 0.0f64;
    for p in &pnls {
        cum += *p;
        if cum > peak {
            peak = cum;
        }
        let dd = peak - cum;
        if dd > max_dd {
            max_dd = dd;
        }
    }

    let unique_days: HashSet<usize> = trades.iter().map(|t| t.day_idx).collect();

    RealizedStats {
        monthly_ev_1ct: total / period_days as f64 * TRADING_DAYS_PER_MONTH,
        total_pnl_1ct: total,
        avg_trade_pnl: mean,
        n_trades: n,
        n_days_traded: unique_days.len(),
        period_days,
        win_rate: wins as f64 / n as f64,
        profit_factor: if gross_loss > 0.0 { Some(gross_win / gross_loss) } else { None },
        max_dd_1ct: max_dd,
        best_trade: pnls.iter().cloned().fold(f64::NEG_INFINITY, f64::max),
        worst_trade: pnls.iter().cloned().fold(f64::INFINITY, f64::min),
    }
}

/// Median that averages the two middle elements of an even-length sample.
pub fn median(sorted: &[f64]) -> f64 {
    let n = sorted.len();
    if n == 0 {
        return f64::NAN;
    }
    if n.is_multiple_of(2) {
        (sorted[n / 2 - 1] + sorted[n / 2]) / 2.0
    } else {
        sorted[n / 2]
    }
}

pub fn sorted_copy(v: &[f64]) -> Vec<f64> {
    let mut s: Vec<f64> = v.iter().copied().filter(|x| x.is_finite()).collect();
    s.sort_by(|a, b| a.partial_cmp(b).unwrap());
    s
}

pub fn mean_of(v: &[f64]) -> f64 {
    let f: Vec<f64> = v.iter().copied().filter(|x| x.is_finite()).collect();
    if f.is_empty() {
        f64::NAN
    } else {
        f.iter().sum::<f64>() / f.len() as f64
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn t(pnl: f64, day: usize) -> RawTrade {
        RawTrade { pnl_1ct: pnl, risk_1ct: 10.0, dir: 0, day_idx: day }
    }

    /// The defect that drove the whole review: one trade must not be scaled to a
    /// month's expectancy.
    #[test]
    fn one_trade_in_a_year_is_not_a_months_expectancy() {
        let s = realized_stats(&[t(1475.50, 42)], 252);
        // 1475.50 over 252 trading days, scaled to 21 days.
        let expect = 1475.50 / 252.0 * 21.0;
        assert!((s.monthly_ev_1ct - expect).abs() < 1e-9);
        assert!(
            s.monthly_ev_1ct < 130.0,
            "a single trade produced a monthly expectancy of ${:.0}",
            s.monthly_ev_1ct
        );
        // The superseded formula would have reported this:
        let legacy = s.avg_trade_pnl * 30.0;
        assert!((legacy - 44_265.0).abs() < 1e-6, "legacy formula check: {legacy}");
        assert!(legacy / s.monthly_ev_1ct > 300.0, "the two differ by orders of magnitude");
    }

    #[test]
    fn expectancy_scales_with_activity_not_trade_size() {
        // Same total profit, same period: identical monthly expectancy whether
        // it came from 1 trade or 100.
        let few = realized_stats(&[t(1000.0, 1)], 252);
        let many: Vec<RawTrade> = (0..100).map(|i| t(10.0, i)).collect();
        let many = realized_stats(&many, 252);
        assert!((few.monthly_ev_1ct - many.monthly_ev_1ct).abs() < 1e-9);
        // But a strategy earning the same per trade, more often, is worth more.
        let busy: Vec<RawTrade> = (0..200).map(|i| t(10.0, i)).collect();
        assert!(realized_stats(&busy, 252).monthly_ev_1ct > many.monthly_ev_1ct);
    }

    #[test]
    fn profit_factor_is_undefined_rather_than_a_sentinel() {
        let all_wins = realized_stats(&[t(100.0, 0), t(50.0, 1)], 252);
        assert!(all_wins.profit_factor.is_none(), "no losses means no ratio, not 999");
        let mixed = realized_stats(&[t(100.0, 0), t(-50.0, 1)], 252);
        assert!((mixed.profit_factor.unwrap() - 2.0).abs() < 1e-9);
    }

    #[test]
    fn drawdown_and_extremes() {
        let s = realized_stats(&[t(100.0, 0), t(-30.0, 1), t(-40.0, 2), t(20.0, 3)], 252);
        assert!((s.max_dd_1ct - 70.0).abs() < 1e-9);
        assert!((s.best_trade - 100.0).abs() < 1e-9);
        assert!((s.worst_trade + 40.0).abs() < 1e-9);
        assert_eq!(s.n_days_traded, 4);
        assert!((s.win_rate - 0.5).abs() < 1e-9);
    }

    #[test]
    fn empty_input_is_zero_not_negative_infinity() {
        let s = realized_stats(&[], 252);
        assert_eq!(s.monthly_ev_1ct, 0.0);
        assert_eq!(s.n_trades, 0);
    }

    #[test]
    fn median_averages_the_middle_pair() {
        assert!((median(&[1.0, 2.0, 3.0, 4.0]) - 2.5).abs() < 1e-9);
        assert!((median(&[1.0, 2.0, 3.0]) - 2.0).abs() < 1e-9);
        assert!(median(&[]).is_nan());
    }
}
