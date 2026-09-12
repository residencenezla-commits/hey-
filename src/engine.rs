//! Grid driver, aggregation, gates and CSV output.

// A NaN-bearing threshold test is written as `!(x >= lo)` rather than `x < lo`
// on purpose: an unmeasurable feature must FAIL a filter, and every comparison
// against NaN is false, so the negated form is the one that rejects it.
#![allow(clippy::neg_cmp_op_on_partial_ord)]

use crate::backtest::{backtest, group_trades_by_day, span_for_years, year_spans, BacktestParams, DayCache};
use crate::config::Args;
use crate::data::{instrument_files, load_data, TzInput};
use crate::ev::{evaluate, EvBreakdown, EvParams};
use crate::features::*;
use crate::mc::{config_seed, mc_challenge, mc_funded, ChallengeResult, FundedDist, McConfig};
use crate::session::{index_sessions, rebuild_session_days, resolve_session};
use crate::stats::{mean_of, median, realized_stats, sorted_copy};
use crate::types::*;
use rand::{Rng, SeedableRng};
use rayon::prelude::*;
use std::collections::{HashMap, HashSet};
use std::fs;
use std::io::Write;
use std::path::Path;
use std::time::Instant;

// ---------------------------------------------------------------------------
// Records
// ---------------------------------------------------------------------------

#[derive(Clone, Debug)]
pub struct FoldRecord {
    pub instrument: String,
    pub entry_mode: String,
    pub bias_mode: String,
    pub exit_mode: String,
    pub window: i32,
    pub rr: f64,
    pub direction: String,
    pub lux_tper: f64,
    pub filter_mode: String,
    pub hurst_min: f64,
    pub entropy_max: f64,
    pub vol_regime: String,
    pub rg_eval: String,
    pub rg_funded: String,
    pub account: String,
    pub test_year: i32,

    pub n_trades: usize,
    pub n_days_traded: usize,
    pub total_period_days: usize,
    pub coverage_pct: f64,
    pub inner_train_ev: f64,
    pub inner_val_ev: f64,
    pub shrinkage_ratio: f64,

    pub realized_monthly_ev_1ct: f64,
    pub realized_total_pnl: f64,
    pub realized_avg_trade: f64,
    pub realized_win_rate: f64,
    pub realized_pf: f64,
    pub realized_max_dd_1ct: f64,
    pub realized_worst_trade: f64,
    pub avg_hurst_used: f64,

    pub mc_pass_rate: f64,
    pub mc_avg_days_to_pass: f64,
    pub mc_mean_ext: f64,
    pub mc_median_ext: f64,
    pub mc_p5_ext: f64,
    pub mc_p_ext_zero: f64,
    pub mc_p_ext_10k: f64,
    pub mc_blowup_rate: f64,
    pub mc_max_pa_dd_p95: f64,
    pub mc_months_held: f64,

    pub ev_per_attempt: f64,
    pub ev_per_funded: f64,
    pub expected_attempts: f64,
    pub bankroll_at_risk: f64,
    pub ev_total_parallel: f64,
    pub legacy_ev: f64,
    pub legacy_error: f64,
}

#[derive(Clone, Debug)]
pub struct DailyPnlRow {
    pub date_code: u32,
    pub instrument: String,
    pub variant: String,
    pub window: i32,
    pub rr: f64,
    pub direction: String,
    pub lux_tper: f64,
    pub filter_mode: String,
    pub hurst_min: f64,
    pub entropy_max: f64,
    pub vol_regime: String,
    pub test_year: i32,
    pub n_trades_day: usize,
    pub daily_pnl_1ct: f64,
}

// ---------------------------------------------------------------------------
// Whitelist
// ---------------------------------------------------------------------------

#[allow(clippy::too_many_arguments)]
pub fn fmt_key(
    inst: &str, em: &str, bm: &str, xm: &str, window: i32, rr: f64, dir: &str,
    lt: f64, fmode: &str, hmin: f64, emax: f64, vr: &str,
) -> String {
    format!("{inst}|{em}|{bm}|{xm}|{window}|{rr:.2}|{dir}|{lt:.2}|{fmode}|{hmin:.2}|{emax:.2}|{vr}")
}

pub fn load_whitelist(path: &str) -> Option<HashSet<String>> {
    if path.is_empty() {
        return None;
    }
    let content = fs::read_to_string(path)
        .unwrap_or_else(|e| panic!("failed to read whitelist {path}: {e}"));
    let mut lines = content.lines();
    let header = lines.next().expect("empty whitelist file");
    let cols: Vec<&str> = header.split(',').map(|s| s.trim()).collect();
    let find = |name: &str| -> usize {
        cols.iter()
            .position(|&c| c == name)
            .unwrap_or_else(|| panic!("whitelist missing column: {name}"))
    };
    let (i_inst, i_em, i_bm, i_xm) = (find("instrument"), find("entry_mode"), find("bias_mode"), find("exit_mode"));
    let (i_w, i_rr, i_dir, i_lt) = (find("window"), find("rr"), find("direction"), find("lux_tper"));
    let (i_fm, i_hm, i_emx, i_vr) = (find("filter_mode"), find("hurst_min"), find("entropy_max"), find("vol_regime"));

    let mut set = HashSet::new();
    let mut skipped = 0usize;
    for (ln, line) in lines.enumerate() {
        if line.trim().is_empty() {
            continue;
        }
        let p: Vec<&str> = line.split(',').map(|s| s.trim()).collect();
        if p.len() < cols.len() {
            skipped += 1;
            continue;
        }
        let num = |i: usize, what: &str| -> f64 {
            p[i].parse::<f64>()
                .unwrap_or_else(|_| panic!("whitelist line {}: {what} = '{}' is not a number", ln + 2, p[i]))
        };
        set.insert(fmt_key(
            p[i_inst], p[i_em], p[i_bm], p[i_xm],
            num(i_w, "window") as i32, num(i_rr, "rr"), p[i_dir],
            num(i_lt, "lux_tper"), p[i_fm], num(i_hm, "hurst_min"), num(i_emx, "entropy_max"), p[i_vr],
        ));
    }
    if skipped > 0 {
        eprintln!("  WARNING: {skipped} whitelist rows had too few columns and were skipped");
    }
    println!("  Loaded {} configs from whitelist {}", set.len(), path);
    Some(set)
}

// ---------------------------------------------------------------------------
// Preflight
// ---------------------------------------------------------------------------

/// Print the measurements that decide whether the search space is meaningful,
/// before spending hours on it.
pub fn preflight(args: &Args) {
    println!("\n---- preflight ----");
    let (mean, sd) = hurst_white_noise_spread(HURST_WIN, 400, args.rng_seed);
    println!(
        "  Hurst on white noise over {HURST_WIN} closes: mean {mean:.4}, sd {sd:.4}\n\
             the h_min sweep spacing is 0.02; a threshold grid finer than this sd\n\
             selects on measurement error rather than persistence"
    );
    if sd > 0.02 {
        println!("    -> sd exceeds the sweep spacing. Consider --no-feature-filters.");
    }

    println!("\n  Cost-model crossover (months held = 6):");
    for ax in all_apex() {
        let x = crate::ev::legacy_error_crossover(&ax, 6.0);
        println!(
            "    {:<7} superseded formula flips sign at pass rate {:.1}%  (err at pr=0: ${:.0}, at pr=1: ${:.0})",
            ax.key,
            x * 100.0,
            crate::ev::legacy_cost_error(&ax, 0.0, 6.0),
            crate::ev::legacy_cost_error(&ax, 1.0, 6.0),
        );
    }

    println!("\n  Per-trade ceiling (max opening range x richest target, minus costs):");
    for sym in ["ES", "NQ", "GC"] {
        let inst = get_inst(sym);
        println!(
            "    {sym}: max range {:.2} pts, ceiling at 1.5x = ${:.2}",
            inst.max_or_points(),
            inst.per_trade_ceiling(1.5, args.slippage_ticks, 4.50)
        );
    }
    println!("-------------------\n");
}

// ---------------------------------------------------------------------------
// Grid
// ---------------------------------------------------------------------------

struct Cell {
    variant: (EntryMode, BiasMode, ExitMode),
    lux: f64,
    window: i32,
    rr: f64,
    dir: &'static str,
    fmode: String,
    hmin: f64,
    emax: f64,
    regime: &'static str,
}

fn grid_configs() -> Vec<(i32, f64, &'static str)> {
    vec![
        (30, 1.0, "long"), (30, 1.0, "both"), (30, 1.0, "short"), (30, 0.5, "long"), (30, 0.33, "long"),
        (45, 1.0, "long"), (45, 1.0, "both"), (45, 1.0, "short"), (45, 0.33, "long"),
        (60, 1.0, "long"), (60, 1.0, "both"), (60, 1.0, "short"),
        (20, 1.0, "long"), (15, 1.0, "long"),
        (5, 1.0, "long"), (5, 1.0, "short"), (5, 1.0, "both"),
        (10, 1.0, "long"), (10, 1.0, "short"), (10, 1.0, "both"),
        (90, 1.0, "long"), (90, 1.0, "short"), (90, 1.0, "both"),
        (120, 1.0, "long"), (120, 1.0, "short"), (120, 1.0, "both"),
        (45, 0.5, "long"), (60, 0.5, "long"), (60, 0.33, "long"),
        (20, 0.5, "long"), (15, 0.5, "long"),
        (20, 1.0, "short"), (15, 1.0, "short"), (20, 0.33, "long"),
    ]
}

fn filter_configs(disabled: bool) -> Vec<(String, f64, f64)> {
    if disabled {
        return vec![("none".into(), 0.0, 0.0)];
    }
    let mut v = vec![("none".to_string(), 0.0, 0.0)];
    for &h in &[0.48, 0.50, 0.52, 0.55] {
        v.push(("hurst".into(), h, 0.0));
    }
    for &e in &[0.5, 1.0, 1.5] {
        v.push(("entropy".into(), 0.0, e));
    }
    for &h in &[0.48, 0.50] {
        for &e in &[0.5, 1.0] {
            v.push(("both".into(), h, e));
        }
    }
    v
}

/// A `--shrinkage-cutoff` at or below this disables the inner-fold filter
/// entirely, including for folds whose inner-train expectancy is not positive.
pub const SHRINKAGE_DISABLED: f64 = -90.0;

const VOL_REGIMES: [&str; 7] = ["all", "calm", "normal", "elevated", "stressed", "crisis", "not_high"];

fn selected_risk_geos(spec: &str) -> Vec<usize> {
    if spec.trim().eq_ignore_ascii_case("all") {
        return (0..N_RISK_GEOS).collect();
    }
    let mut out = Vec::new();
    for tok in spec.split(',') {
        let t = tok.trim();
        if t.is_empty() {
            continue;
        }
        match RG_NAMES.iter().position(|&n| n == t) {
            Some(i) => out.push(i),
            None => panic!("unknown risk geometry '{t}'. Known: {}", RG_NAMES.join(", ")),
        }
    }
    assert!(!out.is_empty(), "--risk-geos selected nothing");
    out.sort_unstable();
    out.dedup();
    out
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

pub fn run(args: &Args) {
    let t0 = Instant::now();
    let stamp = Args::stamp();

    if args.preflight_only {
        preflight(args);
        return;
    }
    fs::create_dir_all(&args.output).expect("cannot create --output directory");

    let mc_cfg = McConfig {
        n_challenge_sims: args.n_sims,
        n_funded_sims: args.n_funded_sims,
        eval_max_days: args.eval_max_days,
        funded_max_days: args.funded_max_days,
        block_days: args.block_days,
        dll_is_breach: args.dll_is_breach,
    };
    mc_cfg.validate();
    assert!(
        args.inner_train_years < args.outer_train_years,
        "--inner-train-years must be less than --outer-train-years"
    );
    assert!((0.0..=1.0).contains(&args.payout_haircut), "--payout-haircut must be in [0,1]");

    let tz = TzInput::parse(&args.tz_input);
    let variants = parse_variants(&args.entry_variants);
    let accounts = all_apex();

    // The engine applies one commission to every account. Reading the field and
    // checking it keeps it live: editing an account's commission now fails
    // loudly instead of doing nothing.
    let commission = accounts[0].comm;
    for a in &accounts {
        assert!(
            (a.comm - commission).abs() < 1e-9,
            "account {} carries commission {:.2} but {} carries {:.2}; \
             per-account commission is not supported — make them equal or extend the cost model",
            a.key, a.comm, accounts[0].key, commission
        );
    }

    let rgs = selected_risk_geos(&args.risk_geos);
    // Both phases sweep the same candidate set; when --split-risk-geo is off the
    // pairing below keeps only the diagonal.
    let (rg_evals, rg_fundeds): (Vec<usize>, Vec<usize>) = if args.signal_only {
        (vec![0], vec![0])
    } else {
        (rgs.clone(), rgs.clone())
    };
    let acct_sel: Vec<Apex> = if args.signal_only {
        accounts.iter().filter(|a| a.key == "it150").cloned().collect()
    } else {
        accounts.clone()
    };

    let (sess_open, sess_close) = resolve_session(&args.session, args.session_open, args.session_close);
    let session_tag = args.session.to_lowercase();
    let ev_params = EvParams {
        payout_haircut: args.payout_haircut,
        parallel_accounts: args.parallel_accounts,
    };

    println!("\n==== ORB engine {stamp} ====");
    println!("  Objective:       expected value per evaluation attempt");
    println!("  Session:         {} (open {sess_open}, close {sess_close})", args.session);
    println!("  Timestamps:      {}", tz.as_str());
    println!("  Walk-forward:    {}y outer ({}y inner train, {}y inner validate), 1y test",
             args.outer_train_years, args.inner_train_years, args.outer_train_years - args.inner_train_years);
    println!("  Evaluation:      up to {} trading days, daily-limit-is-breach={}",
             args.eval_max_days, args.dll_is_breach);
    println!("  Funded:          {} trading days", args.funded_max_days);
    println!("  Risk geometry:   {} eval x {} funded{}",
             rg_evals.len(), rg_fundeds.len(),
             if args.split_risk_geo && !args.signal_only { " (swept independently)" } else { "" });
    println!("  Ranking:         {:.2} x realized + {:.2} x ev_per_attempt",
             args.combined_w_realized, args.combined_w_mc);
    println!("  Payout haircut:  {:.2}   Parallel accounts: {}", args.payout_haircut, args.parallel_accounts);
    if args.no_feature_filters {
        println!("  Feature filters: DISABLED");
    }
    preflight(args);

    let whitelist = load_whitelist(&args.strict_whitelist);
    let insts: Vec<String> = args.instruments.split(',').map(|s| s.trim().to_uppercase()).collect();

    let mut all_folds: Vec<FoldRecord> = Vec::new();

    for sym in &insts {
        println!("\n{}", "=".repeat(78));
        println!("  {sym}  ({} session)", session_tag);
        println!("{}", "=".repeat(78));

        let mut inst = get_inst(sym);
        inst.open_mins = sess_open;
        inst.close_mins = sess_close;
        let cost_1ct = inst.round_turn_cost(args.slippage_ticks, commission);

        let fps = instrument_files(Path::new(&args.data_dir), sym);
        if fps.is_empty() {
            eprintln!("  no CSV files matched {sym}_* in {}; skipping", args.data_dir);
            continue;
        }
        let (mut bars, days_raw, _rep) = load_data(&fps, tz);
        if bars.is_empty() {
            eprintln!("  no usable bars for {sym}; skipping");
            continue;
        }
        let mut days = rebuild_session_days(&mut bars, &days_raw, inst.open_mins, inst.close_mins);
        index_sessions(&bars, &mut days, inst.open_mins, inst.close_mins);
        let with_session: usize = days.iter().filter(|d| d.session_len() > 0).count();
        println!("  {} days, {} with session bars", days.len(), with_session);

        println!("  Precomputing daily closes and Hurst...");
        let day_close = precompute_daily_closes(&bars, &days, &inst);
        let hurst_prev = precompute_hurst_prev(&day_close);
        println!("  Hurst estimated for {} of {} days (rest NaN)",
                 hurst_prev.iter().filter(|h| h.is_finite()).count(), days.len());

        println!("  Computing volatility features and regimes...");
        let vol_features = compute_vol_features(&bars, &days, &inst);
        let day_regimes: Vec<u8> = classify_all_regimes(&vol_features);
        let mut counts = [0usize; 6];
        for &r in &day_regimes {
            counts[r as usize] += 1;
        }
        println!("  Regimes: unknown={} calm={} normal={} elevated={} stressed={} crisis={}",
                 counts[0], counts[1], counts[2], counts[3], counts[4], counts[5]);

        let configs = grid_configs();
        let windows: Vec<i32> = configs.iter().map(|c| c.0).collect();
        println!("  Building per-day cache ({} windows)...", {
            let mut w = windows.clone();
            w.sort_unstable();
            w.dedup();
            w.len()
        });
        let cache = DayCache::build(&bars, &days, &inst, windows);
        let spans = year_spans(&days);

        let mut years: Vec<i32> = days.iter().map(|d| d.year).collect::<HashSet<_>>().into_iter().collect();
        years.sort_unstable();
        let (miny, maxy) = (*years.first().unwrap(), *years.last().unwrap());

        // Enumerate the grid, then evaluate cells in parallel.
        let filters = filter_configs(args.no_feature_filters);
        let lux_values = [0.5f64, 1.0, 1.5];
        let mut cells: Vec<Cell> = Vec::new();
        for &v in &variants {
            for &lux in &lux_values {
                if v.2 == ExitMode::FixedRR && lux != 1.0 {
                    continue;
                }
                for &(window, rr, dir) in &configs {
                    for (fmode, hmin, emax) in &filters {
                        for &regime in &VOL_REGIMES {
                            if let Some(ref wl) = whitelist {
                                let key = fmt_key(sym, v.0.as_str(), v.1.as_str(), v.2.as_str(),
                                                  window, rr, dir, lux, fmode, *hmin, *emax, regime);
                                if !wl.contains(&key) {
                                    continue;
                                }
                            }
                            cells.push(Cell {
                                variant: v, lux, window, rr, dir,
                                fmode: fmode.clone(), hmin: *hmin, emax: *emax, regime,
                            });
                        }
                    }
                }
            }
        }
        println!("  Evaluating {} grid cells across {} candidate test years...",
                 cells.len(), (maxy - (miny + args.outer_train_years) + 1).max(0));

        let results: Vec<(Vec<FoldRecord>, Vec<DailyPnlRow>)> = cells
            .par_iter()
            .map(|cell| {
                evaluate_cell(
                    cell, sym, &bars, &days, &inst, &cache, &spans, &day_regimes, &hurst_prev,
                    cost_1ct, miny, maxy, args, &mc_cfg, &ev_params, &rg_evals, &rg_fundeds, &acct_sel,
                )
            })
            .collect();

        let mut inst_folds: Vec<FoldRecord> = Vec::new();
        let mut inst_daily: Vec<DailyPnlRow> = Vec::new();
        for (f, d) in results {
            inst_folds.extend(f);
            inst_daily.extend(d);
        }
        println!("  {} fold records, {} daily rows", inst_folds.len(), inst_daily.len());

        write_daily_pnl(&inst_daily, &args.output, &session_tag, sym, &stamp);
        write_fold_csv(&inst_folds, &format!("{}/v11_{session_tag}_fold_detail_{sym}.csv", args.output), &stamp);
        all_folds.extend(inst_folds);
    }

    if all_folds.is_empty() {
        println!("\nNo fold records produced. Nothing to aggregate.");
        return;
    }
    if insts.len() > 1 {
        write_fold_csv(&all_folds, &format!("{}/v11_{session_tag}_fold_detail.csv", args.output), &stamp);
    } else {
        println!("  (single instrument: the per-instrument fold detail is the complete set)");
    }

    let agg = aggregate(&all_folds, args);
    write_ranked(&agg, &format!("{}/v11_{session_tag}_full_ranked.csv", args.output), &stamp, args.max_ranked_rows);
    let ready: Vec<&Aggregated> = agg.iter().filter(|a| a.deploy_ready).collect();
    write_ranked_refs(&ready, &format!("{}/v11_{session_tag}_deploy_ready.csv", args.output), &stamp);

    summarize(&agg, &ready, &all_folds, args, t0);
}

#[allow(clippy::too_many_arguments)]
fn evaluate_cell(
    cell: &Cell,
    sym: &str,
    bars: &[Bar],
    days: &[DayMeta],
    inst: &InstCfg,
    cache: &DayCache,
    spans: &HashMap<i32, (usize, usize)>,
    day_regimes: &[u8],
    hurst_prev: &[f64],
    cost_1ct: f64,
    miny: i32,
    maxy: i32,
    args: &Args,
    mc_cfg: &McConfig,
    ev_params: &EvParams,
    rg_evals: &[usize],
    rg_fundeds: &[usize],
    accounts: &[Apex],
) -> (Vec<FoldRecord>, Vec<DailyPnlRow>) {
    let (em, bm, xm) = cell.variant;
    let mut folds: Vec<FoldRecord> = Vec::new();
    let mut daily: Vec<DailyPnlRow> = Vec::new();

    let mk_params = |years: Option<(i32, i32)>| BacktestParams {
        window: cell.window,
        rr: cell.rr,
        direction: cell.dir,
        cost_1ct,
        h_min: cell.hmin,
        e_max: cell.emax,
        year_range: years,
        entry_mode: em,
        bias_mode: bm,
        exit_mode: xm,
        lux_tper: cell.lux,
        vol_regime_filter: cell.regime,
    };

    for test_year in (miny + args.outer_train_years)..=maxy {
        let outer_tr = (test_year - args.outer_train_years, test_year - 1);
        let inner_tr = (outer_tr.0, outer_tr.0 + args.inner_train_years - 1);
        let inner_va = (inner_tr.1 + 1, outer_tr.1);

        let te_span = span_for_years(spans, test_year, test_year);
        let te_days = te_span.1.saturating_sub(te_span.0);
        if te_days < 20 {
            continue;
        }

        let itr_span = span_for_years(spans, inner_tr.0, inner_tr.1);
        let itr = backtest(bars, days, inst, cache, &mk_params(Some(inner_tr)), day_regimes, hurst_prev, itr_span);
        if itr.len() < 30 {
            continue;
        }
        let itr_days = itr_span.1.saturating_sub(itr_span.0).max(1);
        let inner_train_ev = realized_stats(&itr, itr_days).monthly_ev_1ct;

        let iva_span = span_for_years(spans, inner_va.0, inner_va.1);
        let iva = backtest(bars, days, inst, cache, &mk_params(Some(inner_va)), day_regimes, hurst_prev, iva_span);
        if iva.is_empty() {
            continue;
        }
        let iva_days = iva_span.1.saturating_sub(iva_span.0).max(1);
        let inner_val_ev = realized_stats(&iva, iva_days).monthly_ev_1ct;

        // Undefined when inner-train expectancy is not positive, and NaN fails
        // the comparison — so a fold with no in-sample edge is dropped unless
        // the filter is explicitly disabled.
        let shrink = if inner_train_ev > 0.0 { inner_val_ev / inner_train_ev } else { f64::NAN };
        if args.shrinkage_cutoff > SHRINKAGE_DISABLED && !(shrink >= args.shrinkage_cutoff) {
            continue;
        }

        let otr_span = span_for_years(spans, outer_tr.0, outer_tr.1);
        let otr = backtest(bars, days, inst, cache, &mk_params(Some(outer_tr)), day_regimes, hurst_prev, otr_span);
        if otr.len() < 50 {
            continue;
        }

        let test = backtest(bars, days, inst, cache, &mk_params(Some((test_year, test_year))), day_regimes, hurst_prev, te_span);
        if test.is_empty() {
            continue;
        }
        let rs = realized_stats(&test, te_days);

        // Per-day out-of-sample PnL, for joining against external daily features.
        {
            let mut by_day: HashMap<usize, (f64, usize)> = HashMap::new();
            for t in &test {
                let e = by_day.entry(t.day_idx).or_insert((0.0, 0));
                e.0 += t.pnl_1ct;
                e.1 += 1;
            }
            let mut keys: Vec<usize> = by_day.keys().copied().collect();
            keys.sort_unstable();
            for k in keys {
                let (pnl, n) = by_day[&k];
                daily.push(DailyPnlRow {
                    date_code: days[k].date_code,
                    instrument: sym.to_string(),
                    variant: format!("{}/{}/{}", em.as_str(), bm.as_str(), xm.as_str()),
                    window: cell.window, rr: cell.rr, direction: cell.dir.into(), lux_tper: cell.lux,
                    filter_mode: cell.fmode.clone(), hurst_min: cell.hmin, entropy_max: cell.emax,
                    vol_regime: cell.regime.into(), test_year, n_trades_day: n, daily_pnl_1ct: pnl,
                });
            }
        }

        let hurst_used = mean_of(&test.iter().map(|t| hurst_prev[t.day_idx]).collect::<Vec<_>>());
        let longs: Vec<&RawTrade> = otr.iter().filter(|t| t.dir == 0).collect();
        let shorts: Vec<&RawTrade> = otr.iter().filter(|t| t.dir == 1).collect();
        let long_wr = if longs.is_empty() { 0.5 } else { longs.iter().filter(|t| t.pnl_1ct > 0.0).count() as f64 / longs.len() as f64 };
        let short_wr = if shorts.is_empty() { 0.5 } else { shorts.iter().filter(|t| t.pnl_1ct > 0.0).count() as f64 / shorts.len() as f64 };
        let losses: Vec<f64> = otr.iter().filter(|t| t.pnl_1ct < 0.0).map(|t| t.pnl_1ct.abs()).collect();
        let evt_kill = evt_tail_threshold(&losses);
        let otr_by_day = group_trades_by_day(&otr);

        let cov = rs.n_days_traded as f64 / te_days as f64 * 100.0;
        let variant_tag = format!("{}/{}/{}", em.as_str(), bm.as_str(), xm.as_str());

        for ax in accounts {
            // The evaluation result depends only on the evaluation geometry and
            // the funded result only on the funded geometry, so each is computed
            // once and the pairs are formed from the cache. Sweeping both phases
            // therefore costs no extra simulation.
            let seed_for = |rg: usize, tag: i64| {
                config_seed(
                    args.rng_seed,
                    &[sym, &variant_tag, &cell.fmode, cell.regime, cell.dir, &ax.key],
                    &[cell.lux, cell.rr, cell.hmin, cell.emax],
                    &[cell.window as i64, test_year as i64, rg as i64, tag],
                )
            };

            let chal: Vec<ChallengeResult> = rg_evals
                .iter()
                .map(|&rg| mc_challenge(&otr_by_day, ax, rg, mc_cfg, inst.point_value, long_wr, short_wr, evt_kill, seed_for(rg, 1)))
                .collect();
            let best_pr = chal.iter().map(|c| c.pass_rate).fold(0.0f64, f64::max);

            let funded: Vec<FundedDist> = rg_fundeds
                .iter()
                .map(|&rg| {
                    if best_pr > args.mc_funded_min_pr {
                        mc_funded(&otr_by_day, ax, rg, mc_cfg, inst.point_value, long_wr, short_wr, evt_kill, seed_for(rg, 2))
                    } else {
                        FundedDist::default()
                    }
                })
                .collect();

            for (ie, &rge) in rg_evals.iter().enumerate() {
                for (ifd, &rgf) in rg_fundeds.iter().enumerate() {
                    if !args.split_risk_geo && rge != rgf {
                        continue;
                    }
                    let c = chal[ie];
                    let f = funded[ifd];
                    let ev: EvBreakdown = evaluate(ax, c.pass_rate, f.mean_ext, f.p_ext_zero, f.months_held(), ev_params);

                    folds.push(FoldRecord {
                        instrument: sym.to_string(),
                        entry_mode: em.as_str().into(), bias_mode: bm.as_str().into(), exit_mode: xm.as_str().into(),
                        window: cell.window, rr: cell.rr, direction: cell.dir.into(), lux_tper: cell.lux,
                        filter_mode: cell.fmode.clone(), hurst_min: cell.hmin, entropy_max: cell.emax,
                        vol_regime: cell.regime.into(),
                        rg_eval: RG_NAMES[rge].into(), rg_funded: RG_NAMES[rgf].into(),
                        account: ax.key.clone(), test_year,
                        n_trades: rs.n_trades, n_days_traded: rs.n_days_traded,
                        total_period_days: te_days, coverage_pct: cov,
                        inner_train_ev, inner_val_ev, shrinkage_ratio: shrink,
                        realized_monthly_ev_1ct: rs.monthly_ev_1ct,
                        realized_total_pnl: rs.total_pnl_1ct,
                        realized_avg_trade: rs.avg_trade_pnl,
                        realized_win_rate: rs.win_rate,
                        realized_pf: rs.profit_factor.unwrap_or(f64::NAN),
                        realized_max_dd_1ct: rs.max_dd_1ct,
                        realized_worst_trade: rs.worst_trade,
                        avg_hurst_used: hurst_used,
                        mc_pass_rate: c.pass_rate, mc_avg_days_to_pass: c.avg_days_to_pass,
                        mc_mean_ext: f.mean_ext, mc_median_ext: f.median_ext, mc_p5_ext: f.p5_ext,
                        mc_p_ext_zero: f.p_ext_zero, mc_p_ext_10k: f.p_ext_10k,
                        mc_blowup_rate: f.blowup_rate, mc_max_pa_dd_p95: f.max_pa_dd_p95,
                        mc_months_held: f.months_held(),
                        ev_per_attempt: ev.ev_per_attempt, ev_per_funded: ev.ev_per_funded,
                        expected_attempts: ev.expected_attempts,
                        bankroll_at_risk: ev.bankroll_at_risk, ev_total_parallel: ev.ev_total,
                        legacy_ev: ev.legacy_ev, legacy_error: ev.legacy_error,
                    });
                }
            }
        }
    }
    (folds, daily)
}

// ---------------------------------------------------------------------------
// Aggregation and gates
// ---------------------------------------------------------------------------

#[derive(Clone, Debug)]
pub struct Aggregated {
    pub instrument: String,
    pub variant: String,
    pub window: i32,
    pub rr: f64,
    pub direction: String,
    pub lux_tper: f64,
    pub filter_mode: String,
    pub hurst_min: f64,
    pub entropy_max: f64,
    pub vol_regime: String,
    pub rg_eval: String,
    pub rg_funded: String,
    pub account: String,
    /// Stable identity of the group, used as the final sort tiebreaker so the
    /// ranked output does not depend on hash iteration order.
    pub key: String,

    pub folds: usize,
    pub avg_ev_per_attempt: f64,
    pub median_ev_per_attempt: f64,
    pub worst_ev_per_attempt: f64,
    pub positive_ev_folds: usize,
    pub bear_ev: f64,
    pub has_bear: bool,
    pub avg_pass: f64,
    pub worst_pass: f64,
    pub avg_blowup: f64,
    pub avg_p5_ext: f64,
    pub avg_mean_ext: f64,
    pub avg_months_held: f64,
    pub avg_realized_monthly_ev: f64,
    pub worst_realized_monthly_ev: f64,
    pub avg_coverage: f64,
    pub avg_n_trades: f64,
    pub avg_legacy_error: f64,
    pub combined_score: f64,
    pub deploy_ready: bool,
    pub reasons: String,
}

fn aggregate(folds: &[FoldRecord], args: &Args) -> Vec<Aggregated> {
    let mut grouped: HashMap<String, Vec<&FoldRecord>> = HashMap::new();
    for r in folds {
        let key = format!(
            "{}|{}/{}/{}|{}|{:.2}|{}|{:.2}|{}|{:.3}|{:.3}|{}|{}|{}|{}",
            r.instrument, r.entry_mode, r.bias_mode, r.exit_mode, r.window, r.rr, r.direction,
            r.lux_tper, r.filter_mode, r.hurst_min, r.entropy_max, r.vol_regime,
            r.rg_eval, r.rg_funded, r.account
        );
        grouped.entry(key).or_default().push(r);
    }

    let mut keyed: Vec<(String, Vec<&FoldRecord>)> = grouped.into_iter().collect();
    keyed.sort_by(|a, b| a.0.cmp(&b.0));

    let mut out: Vec<Aggregated> = keyed
        .into_iter()
        .map(|(key, g)| {
            let n = g.len();
            let evs: Vec<f64> = g.iter().map(|r| r.ev_per_attempt).collect();
            let sorted = sorted_copy(&evs);
            let head = g[0];
            let bear: Vec<f64> = g.iter().filter(|r| r.test_year == args.bear_year).map(|r| r.ev_per_attempt).collect();
            let realized: Vec<f64> = g.iter().map(|r| r.realized_monthly_ev_1ct).collect();
            let realized_sorted = sorted_copy(&realized);

            let avg_ev = mean_of(&evs);
            let avg_realized = mean_of(&realized);
            Aggregated {
                instrument: head.instrument.clone(),
                variant: format!("{}/{}/{}", head.entry_mode, head.bias_mode, head.exit_mode),
                window: head.window, rr: head.rr, direction: head.direction.clone(),
                lux_tper: head.lux_tper, filter_mode: head.filter_mode.clone(),
                hurst_min: head.hurst_min, entropy_max: head.entropy_max,
                vol_regime: head.vol_regime.clone(),
                rg_eval: head.rg_eval.clone(), rg_funded: head.rg_funded.clone(),
                account: head.account.clone(),
                key,
                folds: n,
                avg_ev_per_attempt: avg_ev,
                median_ev_per_attempt: median(&sorted),
                worst_ev_per_attempt: sorted.first().copied().unwrap_or(f64::NAN),
                positive_ev_folds: evs.iter().filter(|v| **v > 0.0).count(),
                bear_ev: if bear.is_empty() { f64::NAN } else { mean_of(&bear) },
                has_bear: !bear.is_empty(),
                avg_pass: mean_of(&g.iter().map(|r| r.mc_pass_rate).collect::<Vec<_>>()),
                worst_pass: sorted_copy(&g.iter().map(|r| r.mc_pass_rate).collect::<Vec<_>>())
                    .first().copied().unwrap_or(0.0),
                avg_blowup: mean_of(&g.iter().map(|r| r.mc_blowup_rate).collect::<Vec<_>>()),
                avg_p5_ext: mean_of(&g.iter().map(|r| r.mc_p5_ext).collect::<Vec<_>>()),
                avg_mean_ext: mean_of(&g.iter().map(|r| r.mc_mean_ext).collect::<Vec<_>>()),
                avg_months_held: mean_of(&g.iter().map(|r| r.mc_months_held).collect::<Vec<_>>()),
                avg_realized_monthly_ev: avg_realized,
                worst_realized_monthly_ev: realized_sorted.first().copied().unwrap_or(f64::NAN),
                avg_coverage: mean_of(&g.iter().map(|r| r.coverage_pct).collect::<Vec<_>>()),
                avg_n_trades: mean_of(&g.iter().map(|r| r.n_trades as f64).collect::<Vec<_>>()),
                avg_legacy_error: mean_of(&g.iter().map(|r| r.legacy_error).collect::<Vec<_>>()),
                combined_score: args.combined_w_mc * avg_ev + args.combined_w_realized * avg_realized,
                deploy_ready: false,
                reasons: String::new(),
            }
        })
        .collect();

    for a in out.iter_mut() {
        let mut reasons: Vec<String> = Vec::new();
        if a.folds < args.e1_min_folds {
            reasons.push(format!("E1:folds={}<{}", a.folds, args.e1_min_folds));
        }
        if !(a.worst_pass >= args.e2_min_worst_pass) {
            reasons.push(format!("E2:worst_pass={:.0}%", a.worst_pass * 100.0));
        }
        if !(a.avg_blowup <= args.e3_max_blowup) {
            reasons.push(format!("E3:blowup={:.0}%", a.avg_blowup * 100.0));
        }
        if !(a.avg_p5_ext >= args.e4_min_p5_ext) {
            reasons.push(format!("E4:p5_ext=${:.0}", a.avg_p5_ext));
        }
        if a.positive_ev_folds < args.e5_min_positive_ev_folds {
            reasons.push(format!("E5:pos_ev={}/{}", a.positive_ev_folds, a.folds));
        }
        if a.has_bear {
            if !(a.bear_ev >= args.e6_min_bear_ev) {
                reasons.push(format!("E6:bear_ev=${:.0}", a.bear_ev));
            }
        } else {
            reasons.push("E6:no_bear".into());
        }
        a.deploy_ready = reasons.is_empty();
        a.reasons = if a.deploy_ready { "PASSED_ALL".into() } else { reasons.join(";") };
    }

    out.sort_by(|x, y| {
        y.deploy_ready
            .cmp(&x.deploy_ready)
            .then(y.combined_score.partial_cmp(&x.combined_score).unwrap_or(std::cmp::Ordering::Equal))
            .then(y.worst_ev_per_attempt.partial_cmp(&x.worst_ev_per_attempt).unwrap_or(std::cmp::Ordering::Equal))
            .then_with(|| x.key.cmp(&y.key))
    });
    out
}


// ---------------------------------------------------------------------------
// Permutation null
// ---------------------------------------------------------------------------

/// The gate-relevant outcome of one fold, independent of which configuration
/// produced it.
#[derive(Clone, Copy)]
struct Outcome {
    ev: f64,
    pass: f64,
    blowup: f64,
    p5: f64,
    is_bear: bool,
}

/// Count configurations clearing every gate, given a grouping and a set of
/// per-fold outcomes.
fn count_passers(group_of: &[u32], outcomes: &[Outcome], n_groups: usize, args: &Args) -> usize {
    let mut evs: Vec<Vec<f64>> = vec![Vec::new(); n_groups];
    let mut worst_pass = vec![f64::INFINITY; n_groups];
    let mut blow_sum = vec![0.0f64; n_groups];
    let mut p5_sum = vec![0.0f64; n_groups];
    let mut bear_sum = vec![0.0f64; n_groups];
    let mut bear_n = vec![0usize; n_groups];

    for (i, o) in outcomes.iter().enumerate() {
        let g = group_of[i] as usize;
        evs[g].push(o.ev);
        if o.pass < worst_pass[g] {
            worst_pass[g] = o.pass;
        }
        blow_sum[g] += o.blowup;
        p5_sum[g] += o.p5;
        if o.is_bear {
            bear_sum[g] += o.ev;
            bear_n[g] += 1;
        }
    }

    let mut passers = 0usize;
    for g in 0..n_groups {
        let n = evs[g].len();
        if n == 0 || n < args.e1_min_folds {
            continue;
        }
        if !(worst_pass[g] >= args.e2_min_worst_pass) {
            continue;
        }
        if !(blow_sum[g] / n as f64 <= args.e3_max_blowup) {
            continue;
        }
        if !(p5_sum[g] / n as f64 >= args.e4_min_p5_ext) {
            continue;
        }
        if evs[g].iter().filter(|v| **v > 0.0).count() < args.e5_min_positive_ev_folds {
            continue;
        }
        if bear_n[g] == 0 || !(bear_sum[g] / bear_n[g] as f64 >= args.e6_min_bear_ev) {
            continue;
        }
        passers += 1;
    }
    passers
}

/// Estimate how many configurations clear the gates purely by chance.
///
/// Outcomes are shuffled across configurations within each (test year, account)
/// stratum. That destroys the mapping from configuration to result while leaving
/// the distribution of results in each stratum untouched, so the passer count
/// under the shuffle is a direct estimate of the false-positive count at this
/// search width.
fn permutation_null(folds: &[FoldRecord], args: &Args, n_perm: usize) -> Option<(f64, f64)> {
    if n_perm == 0 || folds.is_empty() {
        return None;
    }
    let mut group_ids: HashMap<String, u32> = HashMap::new();
    let mut group_of: Vec<u32> = Vec::with_capacity(folds.len());
    let mut strata: HashMap<(i32, String), Vec<usize>> = HashMap::new();
    let mut outcomes: Vec<Outcome> = Vec::with_capacity(folds.len());

    for (i, r) in folds.iter().enumerate() {
        let key = format!(
            "{}|{}/{}/{}|{}|{:.2}|{}|{:.2}|{}|{:.3}|{:.3}|{}|{}|{}|{}",
            r.instrument, r.entry_mode, r.bias_mode, r.exit_mode, r.window, r.rr, r.direction,
            r.lux_tper, r.filter_mode, r.hurst_min, r.entropy_max, r.vol_regime,
            r.rg_eval, r.rg_funded, r.account
        );
        let next = group_ids.len() as u32;
        let g = *group_ids.entry(key).or_insert(next);
        group_of.push(g);
        strata.entry((r.test_year, r.account.clone())).or_default().push(i);
        outcomes.push(Outcome {
            ev: r.ev_per_attempt,
            pass: r.mc_pass_rate,
            blowup: r.mc_blowup_rate,
            p5: r.mc_p5_ext,
            is_bear: r.test_year == args.bear_year,
        });
    }
    let n_groups = group_ids.len();

    let mut counts: Vec<f64> = Vec::with_capacity(n_perm);
    let mut rng = rand::rngs::StdRng::seed_from_u64(args.rng_seed ^ 0x5045_524D);
    for _ in 0..n_perm {
        let mut shuffled = outcomes.clone();
        for idx in strata.values() {
            // Fisher-Yates within the stratum.
            for k in (1..idx.len()).rev() {
                let j = rng.gen_range(0..=k);
                shuffled.swap(idx[k], idx[j]);
            }
        }
        counts.push(count_passers(&group_of, &shuffled, n_groups, args) as f64);
    }
    let mean = counts.iter().sum::<f64>() / counts.len() as f64;
    let sd = if counts.len() > 1 {
        (counts.iter().map(|c| (c - mean).powi(2)).sum::<f64>() / counts.len() as f64).sqrt()
    } else {
        f64::NAN
    };
    Some((mean, sd))
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

const FOLD_HEADER: &str = "engine,instrument,entry_mode,bias_mode,exit_mode,window,rr,direction,lux_tper,filter_mode,hurst_min,entropy_max,vol_regime,rg_eval,rg_funded,account,test_year,n_trades,n_days_traded,total_period_days,coverage_pct,inner_train_ev,inner_val_ev,shrinkage_ratio,realized_monthly_ev_1ct,realized_total_pnl,realized_avg_trade,realized_win_rate,realized_pf,realized_max_dd_1ct,realized_worst_trade,avg_hurst_used,mc_pass_rate,mc_avg_days_to_pass,mc_mean_ext,mc_median_ext,mc_p5_ext,mc_p_ext_zero,mc_p_ext_10k,mc_blowup_rate,mc_max_pa_dd_p95,mc_months_held,ev_per_attempt,ev_per_funded,expected_attempts,bankroll_at_risk,ev_total_parallel,legacy_ev,legacy_error";

fn num(v: f64, p: usize) -> String {
    if v.is_finite() { format!("{v:.p$}") } else { String::new() }
}

fn write_fold_csv(records: &[FoldRecord], path: &str, stamp: &str) {
    let f = match fs::File::create(path) {
        Ok(f) => f,
        Err(e) => {
            eprintln!("  cannot write {path}: {e}");
            return;
        }
    };
    let mut w = std::io::BufWriter::new(f);
    let _ = writeln!(w, "{FOLD_HEADER}");
    for r in records {
        let _ = writeln!(
            w,
            "{stamp},{},{},{},{},{},{:.2},{},{:.2},{},{:.2},{:.2},{},{},{},{},{},{},{},{},{:.2},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{}",
            r.instrument, r.entry_mode, r.bias_mode, r.exit_mode, r.window, r.rr, r.direction,
            r.lux_tper, r.filter_mode, r.hurst_min, r.entropy_max, r.vol_regime,
            r.rg_eval, r.rg_funded, r.account, r.test_year,
            r.n_trades, r.n_days_traded, r.total_period_days, r.coverage_pct,
            num(r.inner_train_ev, 2), num(r.inner_val_ev, 2), num(r.shrinkage_ratio, 3),
            num(r.realized_monthly_ev_1ct, 2), num(r.realized_total_pnl, 2), num(r.realized_avg_trade, 2),
            num(r.realized_win_rate, 4), num(r.realized_pf, 3), num(r.realized_max_dd_1ct, 2),
            num(r.realized_worst_trade, 2), num(r.avg_hurst_used, 4),
            num(r.mc_pass_rate, 4), num(r.mc_avg_days_to_pass, 2), num(r.mc_mean_ext, 2),
            num(r.mc_median_ext, 2), num(r.mc_p5_ext, 2), num(r.mc_p_ext_zero, 4),
            num(r.mc_p_ext_10k, 4), num(r.mc_blowup_rate, 4), num(r.mc_max_pa_dd_p95, 2),
            num(r.mc_months_held, 4),
            num(r.ev_per_attempt, 2), num(r.ev_per_funded, 2), num(r.expected_attempts, 2),
            num(r.bankroll_at_risk, 2), num(r.ev_total_parallel, 2),
            num(r.legacy_ev, 2), num(r.legacy_error, 2),
        );
    }
    let _ = w.flush();
    let mb = fs::metadata(path).map(|m| m.len() as f64 / 1e6).unwrap_or(0.0);
    println!("  wrote {} rows to {path} ({mb:.0} MB)", records.len());
    if mb > 200.0 {
        println!(
            "    NOTE: a full split-geometry sweep produces one row per \
             (config x fold x eval geometry x funded geometry x account). \
             Narrow with --risk-geos or --entry-variants if this is unwieldy."
        );
    }
}

fn write_daily_pnl(rows: &[DailyPnlRow], out_dir: &str, session_tag: &str, sym: &str, stamp: &str) {
    if rows.is_empty() {
        return;
    }
    let path = format!("{out_dir}/v11_{session_tag}_daily_pnl_{sym}.csv");
    let Ok(f) = fs::File::create(&path) else { return };
    let mut w = std::io::BufWriter::new(f);
    let _ = writeln!(w, "engine,date_code,instrument,variant,window,rr,direction,lux_tper,filter_mode,hurst_min,entropy_max,vol_regime,test_year,n_trades_day,daily_pnl_1ct");
    for r in rows {
        let _ = writeln!(
            w, "{stamp},{},{},{},{},{:.2},{},{:.2},{},{:.2},{:.2},{},{},{},{:.4}",
            r.date_code, r.instrument, r.variant, r.window, r.rr, r.direction, r.lux_tper,
            r.filter_mode, r.hurst_min, r.entropy_max, r.vol_regime, r.test_year,
            r.n_trades_day, r.daily_pnl_1ct
        );
    }
}

const RANK_HEADER: &str = "engine,rank,deploy_ready,reasons,instrument,variant,window,rr,direction,lux_tper,filter_mode,hurst_min,entropy_max,vol_regime,rg_eval,rg_funded,account,folds,avg_ev_per_attempt,median_ev_per_attempt,worst_ev_per_attempt,positive_ev_folds,bear_ev,avg_pass,worst_pass,avg_blowup,avg_p5_ext,avg_mean_ext,avg_months_held,avg_realized_monthly_ev,worst_realized_monthly_ev,avg_coverage,avg_n_trades,avg_legacy_error,combined_score";

fn rank_row(i: usize, a: &Aggregated, stamp: &str) -> String {
    format!(
        "{stamp},{},{},{},{},{},{},{:.2},{},{:.2},{},{:.2},{:.2},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{},{}",
        i + 1,
        if a.deploy_ready { 1 } else { 0 }, a.reasons,
        a.instrument, a.variant, a.window, a.rr, a.direction, a.lux_tper,
        a.filter_mode, a.hurst_min, a.entropy_max, a.vol_regime,
        a.rg_eval, a.rg_funded, a.account, a.folds,
        num(a.avg_ev_per_attempt, 2), num(a.median_ev_per_attempt, 2), num(a.worst_ev_per_attempt, 2),
        a.positive_ev_folds, num(a.bear_ev, 2),
        num(a.avg_pass, 4), num(a.worst_pass, 4), num(a.avg_blowup, 4),
        num(a.avg_p5_ext, 2), num(a.avg_mean_ext, 2), num(a.avg_months_held, 2),
        num(a.avg_realized_monthly_ev, 2), num(a.worst_realized_monthly_ev, 2),
        num(a.avg_coverage, 2), num(a.avg_n_trades, 2), num(a.avg_legacy_error, 2),
        num(a.combined_score, 2),
    )
}

fn write_ranked(agg: &[Aggregated], path: &str, stamp: &str, cap: usize) {
    let n = if cap == 0 { agg.len() } else { cap.min(agg.len()) };
    if n < agg.len() {
        println!(
            "  NOTE: writing the top {n} of {} ranked configurations (--max-ranked-rows)",
            agg.len()
        );
    }
    let refs: Vec<&Aggregated> = agg.iter().take(n).collect();
    write_ranked_refs(&refs, path, stamp);
}

fn write_ranked_refs(agg: &[&Aggregated], path: &str, stamp: &str) {
    let Ok(f) = fs::File::create(path) else {
        eprintln!("  cannot write {path}");
        return;
    };
    let mut w = std::io::BufWriter::new(f);
    let _ = writeln!(w, "{RANK_HEADER}");
    for (i, a) in agg.iter().enumerate() {
        let _ = writeln!(w, "{}", rank_row(i, a, stamp));
    }
    println!("  wrote {} rows to {path}", agg.len());
}

fn summarize(agg: &[Aggregated], ready: &[&Aggregated], all_folds: &[FoldRecord], args: &Args, t0: Instant) {
    println!("\n{}", "=".repeat(78));
    println!("SUMMARY — ranked on expected value per evaluation attempt");
    println!("{}", "=".repeat(78));
    println!("  Configurations aggregated: {}", agg.len());
    println!("  Passing all gates:         {}", ready.len());

    let show: Vec<&Aggregated> = if ready.is_empty() {
        println!("\n  No configuration passed every gate. Nearest misses by expected value:");
        agg.iter().take(15).collect()
    } else {
        println!("\n  Top candidates:");
        ready.iter().take(15).copied().collect()
    };
    for (i, a) in show.iter().enumerate() {
        println!(
            "  {:>3} {} {} W={} RR={:.2} {} filt={} regime={} rg={}/{} acct={}",
            i + 1, a.instrument, a.variant, a.window, a.rr, a.direction,
            a.filter_mode, a.vol_regime, a.rg_eval, a.rg_funded, a.account
        );
        println!(
            "      folds={} ev/attempt=${:.0} (worst ${:.0}, {}/{} positive) pass={:.0}% blowup={:.0}% p5_ext=${:.0}",
            a.folds, a.avg_ev_per_attempt, a.worst_ev_per_attempt, a.positive_ev_folds, a.folds,
            a.avg_pass * 100.0, a.avg_blowup * 100.0, a.avg_p5_ext
        );
        if !a.deploy_ready {
            println!("      blocked by: {}", a.reasons);
        }
    }

    if let Some((mean, sd)) = permutation_null(all_folds, args, args.null_permutations) {
        let observed = ready.len() as f64;
        println!(
            "\n  Multiple testing: {} configurations were gated. Under outcomes shuffled\n               across configurations within each (year, account), {mean:.0}{} pass by chance.",
            agg.len(),
            if sd.is_finite() { format!(" +/- {sd:.0}") } else { String::new() }
        );
        if observed <= mean * 1.5 {
            println!(
                "  {observed:.0} observed vs {mean:.0} expected by chance — this passing set is\n                   not distinguishable from noise at this search width."
            );
        } else {
            println!("  {observed:.0} observed vs {mean:.0} expected by chance.");
        }
    }

    let with_error: Vec<f64> = agg.iter().map(|a| a.avg_legacy_error).filter(|v| v.is_finite()).collect();
    if !with_error.is_empty() {
        let s = sorted_copy(&with_error);
        println!(
            "\n  Superseded cost formula would have mis-stated expected value by \
             ${:.0} to ${:.0} per attempt (median ${:.0}).",
            s.first().unwrap(), s.last().unwrap(), median(&s)
        );
    }
    if args.parallel_accounts > 1 {
        println!(
            "  NOTE: {} accounts on one configuration share a single price path. \
             Expected value scales, the probability of extracting nothing does not.",
            args.parallel_accounts
        );
    }
    println!("\n  Elapsed: {:.1}s", t0.elapsed().as_secs_f64());
}

#[cfg(test)]
mod tests {
    use super::*;
    use clap::Parser;


    fn fold(cfg: usize, year: i32, ev: f64, pass: f64) -> FoldRecord {
        FoldRecord {
            instrument: "ES".into(), entry_mode: "wick".into(), bias_mode: "nobias".into(),
            exit_mode: "fixedrr".into(), window: cfg as i32, rr: 1.0, direction: "long".into(),
            lux_tper: 1.0, filter_mode: "none".into(), hurst_min: 0.0, entropy_max: 0.0,
            vol_regime: "all".into(), rg_eval: "fixed_1".into(), rg_funded: "fixed_1".into(),
            account: "it50".into(), test_year: year,
            n_trades: 50, n_days_traded: 40, total_period_days: 252, coverage_pct: 15.0,
            inner_train_ev: 1.0, inner_val_ev: 1.0, shrinkage_ratio: 1.0,
            realized_monthly_ev_1ct: 10.0, realized_total_pnl: 100.0, realized_avg_trade: 2.0,
            realized_win_rate: 0.5, realized_pf: 1.1, realized_max_dd_1ct: 50.0,
            realized_worst_trade: -30.0, avg_hurst_used: 0.5,
            mc_pass_rate: pass, mc_avg_days_to_pass: 12.0, mc_mean_ext: 5000.0,
            mc_median_ext: 4000.0, mc_p5_ext: 100.0, mc_p_ext_zero: 0.1, mc_p_ext_10k: 0.2,
            mc_blowup_rate: 0.1, mc_max_pa_dd_p95: 900.0, mc_months_held: 6.0,
            ev_per_attempt: ev, ev_per_funded: ev * 4.0, expected_attempts: 4.0,
            bankroll_at_risk: 50.0, ev_total_parallel: ev, legacy_ev: ev, legacy_error: 0.0,
        }
    }

    /// With outcomes that are pure noise, roughly as many configurations clear
    /// the gates under the shuffled null as in the real data — which is the
    /// whole point of reporting the null next to the observed count.
    #[test]
    fn permutation_null_matches_observed_when_there_is_no_signal() {
        use rand::{Rng, SeedableRng};
        let mut rng = rand::rngs::StdRng::seed_from_u64(3);
        let mut args = Args::parse_from(["orb_vol", "--data-dir", "x", "--instruments", "ES"]);
        args.e1_min_folds = 3;
        args.e2_min_worst_pass = 0.20;
        args.e5_min_positive_ev_folds = 3;
        args.bear_year = 2022;
        args.null_permutations = 4;

        let mut folds = Vec::new();
        for cfg in 0..400 {
            for year in 2020..=2023 {
                folds.push(fold(cfg, year, rng.gen::<f64>() * 400.0 - 150.0, rng.gen::<f64>()));
            }
        }
        let observed = aggregate(&folds, &args).iter().filter(|a| a.deploy_ready).count() as f64;
        let (mean, _) = permutation_null(&folds, &args, 4).expect("null computed");
        assert!(observed > 0.0, "fixture produced no passers to compare against");
        let ratio = observed / mean.max(1.0);
        assert!(
            (0.4..2.5).contains(&ratio),
            "noise-only data: {observed} observed vs {mean} under the null (ratio {ratio:.2})"
        );
    }

    /// When one configuration really is better, the observed count exceeds the
    /// null — the check must not flag genuine signal as noise.
    #[test]
    fn permutation_null_falls_below_observed_when_signal_is_real() {
        let mut args = Args::parse_from(["orb_vol", "--data-dir", "x", "--instruments", "ES"]);
        args.e1_min_folds = 3;
        args.e2_min_worst_pass = 0.20;
        args.e5_min_positive_ev_folds = 3;
        args.bear_year = 2022;

        let mut folds = Vec::new();
        for cfg in 0..200 {
            let good = cfg < 60;
            for year in 2020..=2023 {
                let (ev, pass) = if good { (300.0, 0.8) } else { (-200.0, 0.05) };
                folds.push(fold(cfg, year, ev, pass));
            }
        }
        let observed = aggregate(&folds, &args).iter().filter(|a| a.deploy_ready).count() as f64;
        let (mean, _) = permutation_null(&folds, &args, 4).expect("null computed");
        assert!(observed >= 55.0, "expected the 60 good configs to pass, got {observed}");
        assert!(mean < observed * 0.6, "null {mean} should sit well below observed {observed}");
    }

    #[test]
    fn risk_geo_selection_parses_and_rejects() {
        assert_eq!(selected_risk_geos("all").len(), N_RISK_GEOS);
        assert_eq!(selected_risk_geos("fixed_1,cppi"), vec![0, 3]);
        assert!(std::panic::catch_unwind(|| selected_risk_geos("nope")).is_err());
    }

    #[test]
    fn filter_configs_can_be_disabled() {
        assert_eq!(filter_configs(true).len(), 1);
        assert!(filter_configs(false).len() > 1);
    }

    #[test]
    fn grid_windows_are_all_cached() {
        // Every window in the grid must be precomputable, or DayCache panics at
        // lookup time deep inside a parallel sweep.
        let mut ws: Vec<i32> = grid_configs().iter().map(|c| c.0).collect();
        ws.sort_unstable();
        ws.dedup();
        assert!(ws.iter().all(|&w| w > 0));
        assert!(ws.len() >= 5);
    }
}
