//! Command-line configuration.

use clap::Parser;

pub const ENGINE_VERSION: &str = env!("CARGO_PKG_VERSION");
pub const GIT_REV: &str = env!("ORB_GIT_REV");

#[derive(Parser, Debug, Clone)]
#[command(
    name = "orb_vol",
    version,
    about = "Opening-range-breakout engine ranked on prop-firm evaluation and payout expected value",
    // Several thresholds are legitimately negative; without this clap reads
    // `--e6-min-bear-ev -100` as an unknown flag.
    allow_negative_numbers = true
)]
pub struct Args {
    #[arg(long)] pub data_dir: String,
    #[arg(long)] pub instruments: String,
    #[arg(long, default_value = "./v11_results")] pub output: String,

    /// How to read timestamps in the input files: utc | offset | et.
    ///
    /// There is no safe default here. Reading offset-bearing timestamps as UTC
    /// displaces every bar by four or five hours, varying across daylight
    /// saving, so the format is declared and then verified rather than guessed.
    #[arg(long, default_value = "utc")] pub tz_input: String,

    /// Which firm's rule set to model: apex | topstep.
    #[arg(long, default_value = "apex")] pub firm: String,

    // ---- Monte Carlo ----
    #[arg(long, default_value = "10000")] pub n_sims: usize,
    #[arg(long, default_value = "2000")] pub n_funded_sims: usize,
    #[arg(long, default_value = "5")] pub block_days: usize,
    /// Override the evaluation length, in TRADING days.
    ///
    /// 0 uses the product's own window: Apex allows 30 *calendar* days, which is
    /// about 21 sessions. The previous engine ran 30 trading days against that
    /// same rule, giving roughly 40% more opportunity than the product allows.
    #[arg(long, default_value = "0")] pub eval_max_days: usize,
    /// Cap on evaluation length when the product is untimed (Topstep's combine
    /// runs as long as the subscription is paid).
    #[arg(long, default_value = "120")] pub eval_untimed_cap: usize,
    /// Trading days of funded account to simulate.
    #[arg(long, default_value = "120")] pub funded_max_days: usize,
    /// Treat the daily loss limit as a rule breach that fails the account,
    /// rather than a cap that truncates the day.
    #[arg(long, default_value = "true")] pub dll_is_breach: bool,
    /// Run the funded simulation only when the evaluation pass rate exceeds this.
    #[arg(long, default_value = "0.02")] pub mc_funded_min_pr: f64,
    #[arg(long, default_value = "42")] pub rng_seed: u64,

    // ---- Costs and expected value ----
    #[arg(long, default_value = "2")] pub slippage_ticks: i32,
    /// Fraction of simulated extraction assumed actually received.
    #[arg(long, default_value = "1.0")] pub payout_haircut: f64,
    /// Accounts intended to be run on one configuration simultaneously.
    #[arg(long, default_value = "1")] pub parallel_accounts: usize,

    // ---- Walk-forward ----
    #[arg(long, default_value = "6")] pub outer_train_years: i32,
    #[arg(long, default_value = "4")] pub inner_train_years: i32,
    #[arg(long, default_value = "0.70")] pub shrinkage_cutoff: f64,

    // ---- Search space ----
    #[arg(long, default_value = "all")] pub entry_variants: String,
    /// Comma-separated risk geometry names, or "all".
    #[arg(long, default_value = "all")] pub risk_geos: String,
    /// Sweep the evaluation and funded risk geometries independently.
    ///
    /// The two phases want opposite policies — capped downside rewards variance
    /// during the evaluation, while an account plus sunk activation at risk
    /// rewards survival afterwards. Sharing one geometry cannot express that.
    /// Costs no extra simulation: the per-phase results are computed once each
    /// and then combined.
    #[arg(long, default_value = "true")] pub split_risk_geo: bool,
    /// Skip the Hurst and entropy filters. They sweep thresholds finer than the
    /// estimators' own standard error; see `hurst_white_noise_spread`.
    #[arg(long, default_value = "false")] pub no_feature_filters: bool,
    #[arg(long, default_value = "")] pub strict_whitelist: String,

    // ---- Session ----
    #[arg(long, default_value = "ny")] pub session: String,
    #[arg(long, default_value = "-1")] pub session_open: i32,
    #[arg(long, default_value = "-1")] pub session_close: i32,

    // ---- Ranking ----
    /// Weight on the naked one-contract signal expectancy.
    ///
    /// Defaults to zero: the objective is expected value per evaluation
    /// attempt, and the one-contract figure is an input to that, never the
    /// objective itself. Raise it only to deliberately trade payout value for
    /// signal quality.
    #[arg(long, default_value = "0.0")] pub combined_w_realized: f64,
    /// Weight on expected value per evaluation attempt.
    #[arg(long, default_value = "1.0")] pub combined_w_mc: f64,

    // ---- Gates, aligned to the objective ----
    /// Minimum out-of-sample folds. Set this from the observed fold attrition,
    /// not from the number of candidate years.
    #[arg(long, default_value = "3")] pub e1_min_folds: usize,
    /// Minimum evaluation pass rate in the worst fold.
    #[arg(long, default_value = "0.20")] pub e2_min_worst_pass: f64,
    /// Maximum mean funded-account blowup rate.
    #[arg(long, default_value = "0.50")] pub e3_max_blowup: f64,
    /// Minimum 5th-percentile extraction, in dollars.
    #[arg(long, default_value = "0.0")] pub e4_min_p5_ext: f64,
    /// Minimum folds with positive expected value per attempt.
    #[arg(long, default_value = "3")] pub e5_min_positive_ev_folds: usize,
    /// Minimum expected value per attempt in the designated bear year.
    #[arg(long, default_value = "-100.0")] pub e6_min_bear_ev: f64,
    #[arg(long, default_value = "2022")] pub bear_year: i32,

    /// Cap rows written to the ranked CSV (0 = unlimited). A full split-geometry
    /// sweep aggregates over a million configurations; writing them all produces
    /// files in the hundreds of megabytes.
    #[arg(long, default_value = "100000")] pub max_ranked_rows: usize,
    /// Permutation nulls used to estimate how many configurations clear the
    /// gates by chance. 0 disables the check.
    ///
    /// The gates are applied to a search space of roughly a million
    /// configurations with no multiple-comparison correction, so the number that
    /// pass is only meaningful against the number that would pass on shuffled
    /// outcomes.
    #[arg(long, default_value = "1")] pub null_permutations: usize,

    /// Restrict to one risk geometry and account, to look at the signal alone.
    #[arg(long, default_value = "false")] pub signal_only: bool,
    /// Print the diagnostic preflight (estimator spreads, cost-model crossover)
    /// and exit without running the sweep.
    #[arg(long, default_value = "false")] pub preflight_only: bool,
}

impl Args {
    pub fn stamp() -> String {
        format!("{ENGINE_VERSION}+{GIT_REV}")
    }
}
