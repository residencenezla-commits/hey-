//! Whole-pipeline tests: generate market data, run the engine, check the CSVs.
//!
//! These are the tests that would have caught the defects the unit tests cannot
//! see on their own — a per-trade profit that exceeds what the rules permit, a
//! fill outside the session, and a run that cannot be reproduced.

use clap::Parser;
use orb_vol::config::Args;
use orb_vol::types::get_inst;
use std::fs;
use std::path::{Path, PathBuf};

/// Deterministic pseudo-random generator, so fixtures need no dependencies and
/// never shift under us.
struct Lcg(u64);
impl Lcg {
    fn next_f64(&mut self) -> f64 {
        self.0 = self.0.wrapping_mul(6_364_136_223_846_793_005).wrapping_add(1_442_695_040_888_963_407);
        ((self.0 >> 11) as f64) / ((1u64 << 53) as f64)
    }
    fn gauss(&mut self) -> f64 {
        // Irwin-Hall approximation; adequate for a price-path fixture.
        (0..6).map(|_| self.next_f64()).sum::<f64>() - 3.0
    }
}

fn day_of_week(mut y: i32, mut m: u32, d: u32) -> u32 {
    if m < 3 {
        y -= 1;
        m += 12;
    }
    let k = y % 100;
    let j = y / 100;
    let h = (d as i32 + (13 * (m as i32 + 1)) / 5 + k + k / 4 + j / 4 + 5 * j) % 7;
    ((h + 5) % 7) as u32 // 0 = Monday
}

fn days_in_month(y: i32, m: u32) -> u32 {
    match m {
        1 | 3 | 5 | 7 | 8 | 10 | 12 => 31,
        4 | 6 | 9 | 11 => 30,
        _ => {
            if (y % 4 == 0 && y % 100 != 0) || y % 400 == 0 { 29 } else { 28 }
        }
    }
}

/// Write a year of 24-hour bars. Timestamps are UTC; ET is UTC-5 in the fixture,
/// so 14:30 UTC is the 09:30 session open. Overnight bars are included on
/// purpose: an engine that scans past the session close will find them.
fn write_year(dir: &Path, symbol: &str, year: i32, rng: &mut Lcg, start_px: f64) -> f64 {
    let mut rows = String::from("id,timestamp,symbol,high,low,close,volume\n");
    let mut px = start_px;
    let mut id = 0usize;
    for month in 1..=12u32 {
        for day in 1..=days_in_month(year, month) {
            if day_of_week(year, month, day) >= 5 {
                continue;
            }
            for slot in (0..1440).step_by(10) {
                // Mild intraday drift so breakout folds have a real in-sample edge.
                let drift = if slot >= 570 && slot < 960 { 0.00012 } else { -0.00004 };
                px *= 1.0 + rng.gauss() * 0.0006 + drift;
                let hi = px * (1.0 + rng.next_f64() * 0.0008);
                let lo = px * (1.0 - rng.next_f64() * 0.0008);
                let cl = px.clamp(lo, hi);
                // ET slot -> UTC by adding 5 hours, wrapping into the next day.
                let utc_min = slot + 300;
                let (dd, hh, mm) = (day + (utc_min / 1440), (utc_min % 1440) / 60, utc_min % 60);
                if dd > days_in_month(year, month) {
                    continue;
                }
                rows.push_str(&format!(
                    "{id},{year:04}-{month:02}-{dd:02} {hh:02}:{mm:02}:00,{symbol},{hi:.2},{lo:.2},{cl:.2},100\n"
                ));
                id += 1;
            }
        }
    }
    fs::write(dir.join(format!("{symbol}_{year}.csv")), rows).expect("write fixture");
    px
}

fn fixture(tag: &str, years: std::ops::RangeInclusive<i32>) -> PathBuf {
    let dir = std::env::temp_dir().join(format!("orb_e2e_{tag}_{}", std::process::id()));
    let _ = fs::remove_dir_all(&dir);
    fs::create_dir_all(&dir).expect("create fixture dir");
    let mut rng = Lcg(0x5EED_1234);
    let mut px = 4200.0;
    for y in years {
        px = write_year(&dir, "ES", y, &mut rng, px);
    }
    dir
}

fn base_args(data: &Path, out: &Path) -> Vec<String> {
    [
        "orb_vol",
        "--data-dir", data.to_str().unwrap(),
        "--instruments", "ES",
        "--output", out.to_str().unwrap(),
        "--session", "ny",
        "--tz-input", "utc",
        "--entry-variants", "wick/nobias/fixedrr",
        "--no-feature-filters",
        "--risk-geos", "fixed_1,cppi",
        // Only the verified 50K products; the larger sizes are flagged inferred
        // and the engine refuses them without --allow-unverified.
        "--accounts", "apex_50_it_std,apex_50_eod_std",
        "--n-sims", "200",
        "--n-funded-sims", "100",
        "--outer-train-years", "3",
        "--inner-train-years", "2",
        "--shrinkage-cutoff", "-99",
        "--e1-min-folds", "1",
        "--e5-min-positive-ev-folds", "1",
    ]
    .iter()
    .map(|s| s.to_string())
    .collect()
}

fn read_csv(path: &Path) -> (Vec<String>, Vec<Vec<String>>) {
    let text = fs::read_to_string(path).unwrap_or_else(|e| panic!("read {}: {e}", path.display()));
    let mut lines = text.lines();
    let header: Vec<String> = lines.next().expect("header").split(',').map(|s| s.to_string()).collect();
    let rows = lines
        .filter(|l| !l.trim().is_empty())
        .map(|l| l.split(',').map(|s| s.to_string()).collect())
        .collect();
    (header, rows)
}

fn col(header: &[String], name: &str) -> usize {
    header.iter().position(|h| h == name).unwrap_or_else(|| panic!("no column {name}"))
}

/// The end-to-end form of the property that exposed the contaminated trade set:
/// no day's profit may exceed what the rules physically allow.
#[test]
fn no_daily_pnl_exceeds_the_engine_ceiling() {
    let data = fixture("ceiling", 2015..=2020);
    let out = data.join("out");
    let args = Args::parse_from(base_args(&data, &out));
    orb_vol::engine::run(&args);

    let inst = get_inst("ES");
    // wick/nobias/fixedrr at rr <= 1.0, both directions -> at most two trades a
    // day, each capped by the richest target the rules allow.
    let ceiling = inst.per_trade_ceiling(1.0, args.slippage_ticks, 4.50) * 2.0;

    let path = out.join("v11_ny_daily_pnl_ES.csv");
    let (header, rows) = read_csv(&path);
    let (ci, cn) = (col(&header, "daily_pnl_1ct"), col(&header, "n_trades_day"));
    assert!(!rows.is_empty(), "no daily rows produced");

    let mut worst = f64::NEG_INFINITY;
    for r in &rows {
        let pnl: f64 = r[ci].parse().unwrap();
        let n: usize = r[cn].parse().unwrap();
        assert!(n <= 2, "{n} trades in one day for a single-direction-pair config");
        worst = worst.max(pnl);
        assert!(
            pnl <= ceiling + 1e-6,
            "daily PnL ${pnl:.2} exceeds the ${ceiling:.2} ceiling — a fill outside the rules"
        );
    }
    println!("checked {} daily rows, largest ${worst:.2} against ceiling ${ceiling:.2}", rows.len());
    let _ = fs::remove_dir_all(&data);
}

/// Two runs with the same seed must produce byte-identical output.
#[test]
fn runs_are_reproducible_from_the_seed() {
    let data = fixture("repro", 2015..=2019);
    let (a, b) = (data.join("a"), data.join("b"));
    for out in [&a, &b] {
        let mut argv = base_args(&data, out);
        argv.extend(["--rng-seed".into(), "1234".into()]);
        orb_vol::engine::run(&Args::parse_from(argv));
    }
    for name in ["v11_ny_fold_detail_ES.csv", "v11_ny_full_ranked.csv", "v11_ny_daily_pnl_ES.csv"] {
        let (x, y) = (fs::read(a.join(name)).unwrap(), fs::read(b.join(name)).unwrap());
        assert_eq!(x, y, "{name} differs between two identically seeded runs");
        assert!(x.len() > 200, "{name} is suspiciously small");
    }

    // A different seed must actually change the simulation.
    let c = data.join("c");
    let mut argv = base_args(&data, &c);
    argv.extend(["--rng-seed".into(), "9999".into()]);
    orb_vol::engine::run(&Args::parse_from(argv));
    let different = fs::read(c.join("v11_ny_fold_detail_ES.csv")).unwrap();
    assert_ne!(
        fs::read(a.join("v11_ny_fold_detail_ES.csv")).unwrap(),
        different,
        "changing the seed changed nothing — the simulation is not actually seeded"
    );
    let _ = fs::remove_dir_all(&data);
}

/// The expected-value columns must reconcile with the cost model by hand, and
/// the superseded formula's error must match its closed form.
#[test]
fn expected_value_columns_reconcile_with_the_cost_model() {
    let data = fixture("ev", 2015..=2019);
    let out = data.join("out");
    orb_vol::engine::run(&Args::parse_from(base_args(&data, &out)));

    let (header, rows) = read_csv(&out.join("v11_ny_fold_detail_ES.csv"));
    assert!(!rows.is_empty(), "no fold records produced");
    let accounts = orb_vol::types::apex_accounts();
    let (c_acct, c_pr) = (col(&header, "account"), col(&header, "mc_pass_rate"));
    let c_ext = col(&header, "mc_mean_ext");
    let c_months = col(&header, "mc_months_held");
    let c_ev = col(&header, "ev_per_attempt");
    let c_legacy = col(&header, "legacy_ev");
    let c_err = col(&header, "legacy_error");

    let mut checked = 0usize;
    for r in rows.iter().take(4000) {
        let ax = accounts.iter().find(|a| a.key == r[c_acct]).expect("known account");
        let pr: f64 = r[c_pr].parse().unwrap();
        let ext: f64 = r[c_ext].parse().unwrap();
        let months: f64 = r[c_months].parse().unwrap();
        let ev: f64 = r[c_ev].parse().unwrap();
        let legacy: f64 = r[c_legacy].parse().unwrap();
        let err: f64 = r[c_err].parse().unwrap();

        // Extraction is gross account profit; the split decides what is paid,
        // and a repeated player pays the pack price, not the single price.
        let net = ax.trader_share(ext);
        let attempts = if pr > 0.0 { 1.0 / pr } else { f64::INFINITY };
        let effective_fee = if attempts.is_finite() {
            ax.cost_for_attempts(attempts) / attempts
        } else {
            ax.fee_pack1
        };
        let expect_ev = pr * (net - (ax.act + ax.pamo * months)) - effective_fee;
        assert!((ev - expect_ev).abs() < 0.05, "{} ev {ev} vs {expect_ev}", ax.key);

        let expect_legacy = pr * ext - (ax.fee + ax.act + ax.pamo * 2.0);
        assert!((legacy - expect_legacy).abs() < 0.05, "{} legacy {legacy} vs {expect_legacy}", ax.key);

        let closed_form = orb_vol::ev::legacy_cost_error(ax, pr, months) + (effective_fee - ax.fee);
        assert!((err - closed_form).abs() < 0.05, "{} error {err} vs closed form {closed_form}", ax.key);

        // A configuration that never passes costs exactly one evaluation fee.
        if pr == 0.0 {
            assert!((ev + ax.fee).abs() < 0.05, "zero pass rate should cost exactly the fee, got {ev}");
        }
        checked += 1;
    }
    assert!(checked > 50, "only checked {checked} rows");
    let _ = fs::remove_dir_all(&data);
}

/// Sweeping the two phases independently must produce mixed pairs, not only the
/// diagonal — otherwise the split is not actually happening.
#[test]
fn risk_geometry_is_swept_per_phase() {
    let data = fixture("split", 2015..=2019);
    let out = data.join("out");
    orb_vol::engine::run(&Args::parse_from(base_args(&data, &out)));

    let (header, rows) = read_csv(&out.join("v11_ny_fold_detail_ES.csv"));
    let (ce, cf) = (col(&header, "rg_eval"), col(&header, "rg_funded"));
    let mixed = rows.iter().filter(|r| r[ce] != r[cf]).count();
    let diagonal = rows.iter().filter(|r| r[ce] == r[cf]).count();
    assert!(mixed > 0, "no mixed (eval, funded) geometry pairs were produced");
    assert!(diagonal > 0, "the diagonal pairs are missing");
    println!("{mixed} mixed pairs, {diagonal} diagonal");
    let _ = fs::remove_dir_all(&data);
}

/// Declaring the wrong input timezone must fail loudly rather than silently
/// displacing every bar by four or five hours.
#[test]
fn mis_declared_timezone_is_rejected() {
    let dir = std::env::temp_dir().join(format!("orb_tz_{}", std::process::id()));
    let _ = fs::remove_dir_all(&dir);
    fs::create_dir_all(&dir).unwrap();
    let mut rows = String::from("id,timestamp,symbol,high,low,close,volume\n");
    for i in 0..50 {
        rows.push_str(&format!(
            "{i},2024-07-15 09:{:02}:00-04:00,ES,4001.00,3999.00,4000.00,10\n",
            i % 60
        ));
    }
    fs::write(dir.join("ES_2024.csv"), rows).unwrap();

    let mut rep = orb_vol::data::LoadReport::default();
    // Declared UTC, but the rows carry a real offset.
    assert!(orb_vol::data::parse_et("2024-07-15 09:30:00-04:00", orb_vol::data::TzInput::Utc, &mut rep).is_none());
    // Declared offset: honoured, and 09:30 ET is minute 570.
    let (tm, _, _) =
        orb_vol::data::parse_et("2024-07-15 09:30:00-04:00", orb_vol::data::TzInput::Offset, &mut rep).unwrap();
    assert_eq!(tm, 570);

    let files = orb_vol::data::instrument_files(&dir, "ES");
    let (bars, _, rep) = orb_vol::data::load_data(&files, orb_vol::data::TzInput::Utc);
    assert!(bars.is_empty(), "offset rows must not load under --tz-input utc");
    assert_eq!(rep.bad_rows, 50);

    let (bars, _, _) = orb_vol::data::load_data(&files, orb_vol::data::TzInput::Offset);
    assert_eq!(bars.len(), 50);
    assert!(bars.iter().all(|b| b.time_mins >= 540 && b.time_mins < 600));
    let _ = fs::remove_dir_all(&dir);
}
