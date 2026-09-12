# orb_vol

Opening-range-breakout engine, ranked on **expected value per prop-firm
evaluation attempt**.

```
EV = pass_rate × (extraction − activation − monthly × months_held) − evaluation_fee
```

The one-contract signal return is an *input* to that, never the objective.

This is a rewrite of the v8.1/v10 engine. It keeps the strategy definition, the
nested walk-forward, the block bootstrap and the account model, and changes the
things that determined the answer.

## Quick start

```bash
cargo test                      # 67 tests, no fixtures required
cargo run --release -- --data-dir ./data --instruments ES --preflight-only
cargo run --release -- --data-dir ./data --instruments ES,NQ --session ny --tz-input utc
```

`--preflight-only` prints the three measurements that decide whether a sweep is
worth running, and exits.

## What changed, and why it mattered

### The ranking did not implement the objective

`combined_score` was `0.70 × realized_ev + 0.30 × mc_ev` — most of the weight on
the naked one-contract figure, which is not what gets paid. Defaults are now
`--combined-w-realized 0.0 --combined-w-mc 1.0`.

### Expectancy was a per-trade mean × 30

`mean_trade_pnl * 30.0` assumes a trade every calendar day. A configuration
firing twice a year had its per-trade mean extrapolated roughly a hundredfold,
which is why every top-ranked result in the old logs showed `folds=1`.

The old headline numbers reconstruct exactly from that formula. NQ:

```
max opening range   200 ticks × 0.25        = 50.00 points   (MAX_OR_TICKS)
max target          1.5 × 50.00 × $20       = $1,500.00
round turn          2 × 0.25 × 20 × 2 + 4.50 = −$24.50
                                              ──────────
ceiling per trade                             $1,475.50
reported  44,265 ÷ 30                         $1,475.50      ← identical
```

Gold reproduces the same way: `84,165 ÷ 30 = $2,805.50` is a 19.00-point range at
the same target. Both were **one maximum-target win multiplied by thirty**.

Now `total_pnl / period_days × 21`. `backtest::tests::no_trade_exceeds_the_engine_ceiling`
and `end_to_end::no_daily_pnl_exceeds_the_engine_ceiling` assert the bound that
made this visible.

### The entry scan ran past the session close

`in_session()` was called for volatility features, daily closes and the opening
range — never in the entry or exit loop. For `ny` and `london` a `DayMeta` spans
the whole ET calendar day, which on a 24-hour instrument is ~1,000 out-of-session
bars. A wick-break trigger among them filled at the stale opening-range level and
exited at the current close, booking any overnight gap as profit.

`DayMeta` now carries `sess_start`/`sess_end` and nothing outside that range is
read. Since both Monte Carlo simulators resample this trade set, it was the
single largest contaminant of the objective.

### The cost term was wrong in both directions

`tc = fee + act + pamo * 2.0` charged activation and platform fees on attempts
that were never funded, against a simulation running up to 120 trading days.
Subtracting the correct expression leaves an error depending only on pass rate:

```
error(pr) = act × (pr − 1) + pamo × (pr × M − 2)

EOD accounts   609·pr − 269    changes sign at pr = 44.2%
IT  accounts   589·pr − 249    changes sign at pr = 42.3%
```

The evaluation fee cancels — it was the one cost the old formula got right. Both
crossovers sit inside the 5.4%–64.2% band the old runs actually produced, so no
constant correction recovers it. `months_held` now comes from the simulated
holding period, and `legacy_ev`/`legacy_error` columns carry the difference.

### One risk geometry was forced across both phases

The evaluation has capped downside, so variance relative to the drawdown is an
asset; the funded phase risks the account plus sunk activation, so survival to
the safety-net lock is what matters. `rg` was shared, so the search could not
express the pair.

`--split-risk-geo` (default on) sweeps them independently. **This costs no extra
simulation**: the evaluation result depends only on `rg_eval` and the funded
result only on `rg_funded`, so each is computed once per account and the 49 pairs
are formed from the cache — the same 14 simulations the old code ran for 7.

### Every simulator bias pointed the same way

| Fix | Effect on pass rate |
|---|---|
| Tail-capped loss no longer breaks past the intraday drawdown check | was over-stated |
| `--dll-is-breach` (default on) fails the account instead of capping the day | was over-stated |
| `--eval-max-days` replaces a hard-coded 30 trading days | was **under**-stated |

The 30-day cap discarded every path that would have passed later, which
penalises low-variance configurations specifically. Set it to your product's
actual rule.

### Other corrections

- **Timezone is declared, not guessed.** `--tz-input utc|offset|et`. The old
  loader stripped a trailing UTC offset and read the remainder as UTC — correct
  only for `+00:00`. Offset-bearing rows under `--tz-input utc` are now rejected
  rather than silently displaced four or five hours.
- **Same-bar stop-outs are losses.** A wick-break entry whose own bar swept the
  stop was discarded *and* the loop broke, censoring the worst trades in the
  population out of every statistic.
- **Volatility regimes are balanced.** The old classifier exhausted `n_high`
  before consulting `n_low`, producing 0.8% calm days against 5.7% crisis. Days
  are now ranked by composite score against the prior year and cut at quintiles,
  so each regime holds about a fifth. Note a fixed cut on the *mean of three
  ranks* is not enough — that mean concentrates around 0.5 — hence the second
  ranking pass.
- **Unmeasurable features fail filters.** Hurst and entropy return `NaN` rather
  than a `0.5` sentinel that cleared every `h_min ≤ 0.50` threshold for the first
  hundred days of every instrument.
- **Seeds cover every config axis.** The old seed omitted variant, `lux_tper`,
  `filter_mode`, `vol_regime` and instrument, correlating the pass-rate estimates
  that the gates then compared.
- **Gates match the objective.** E1–E6 replace the realized-EV gates: minimum
  folds, worst-fold pass rate, blowup ceiling, 5th-percentile extraction floor,
  positive-EV folds, bear-year floor. `p5_ext` and `blowup_rate` were previously
  computed and discarded. Set `--e1-min-folds` from observed attrition — the old
  `G1=8` was unreachable against a filter that left one or two.
- **Parallel accounts do not diversify.** `--parallel-accounts N` reports
  `bankroll_at_risk` and `ev_total`, and leaves `p_total_zero` unchanged: N
  accounts on one configuration share one price path.
- Anchored instrument matching (`ES` no longer matches `MES_2024.CSV` or
  `RESULTS.CSV`); `--block-days 0` is rejected instead of hanging; `Apex.comm` is
  read and validated instead of being dead; ranked output is deterministic
  regardless of hash iteration order.

## Preflight

Three measurements, before spending hours:

```
Hurst on white noise over 100 closes: mean 0.5593, sd 0.1144
  -> sd exceeds the sweep spacing. Consider --no-feature-filters.
```

The `h_min` grid sweeps 0.48/0.50/0.52/0.55 — spacing 0.02, against an estimator
whose standard deviation on **pure noise** is 0.11, with a mean of 0.56 that sits
*above* the entire sweep range. More than half of random days clear every
threshold in the grid. Until that changes, `--no-feature-filters`.

## Performance

The full 45,696-cell grid over 13 years of one instrument runs in **under two
minutes** where the previous engine took hours. Three changes:

- `DayCache` precomputes the opening range, its sample entropy and the first
  post-range bar per `(window, day)`. Entropy is O(n²) in the window and was
  recomputed for every target, direction, filter and regime sharing a window.
- `year_spans` maps each year to a contiguous day range instead of re-scanning
  all bars inside every fold.
- The configuration grid is parallelised, rather than only the Monte Carlo.

## Layout

| File | Contents |
|---|---|
| `types.rs` | Bars, days, accounts, risk geometries, per-trade ceiling |
| `session.rs` | Session resolution, session-day rebuild, session indexing |
| `data.rs` | CSV loading, declared-timezone parsing |
| `features.rs` | Volatility regimes, Hurst, sample entropy, EVT tail |
| `backtest.rs` | The strategy, `DayCache`, year spans |
| `mc.rs` | Evaluation and funded simulators |
| `ev.rs` | Cost model, retry model, parallel-account arithmetic |
| `stats.rs` | Realised statistics |
| `engine.rs` | Grid driver, aggregation, gates, CSV output |

Every CSV carries an `engine` column stamped with version and git revision, so a
result set always names the code that produced it.

## Caveat

The account terms in `all_apex()` — targets, drawdowns, ladders, consistency
percentages, fees, and the evaluation duration — are transcribed, not verified.
Every one of them feeds expected value directly. Check them against the firm's
current published terms before trusting any output.
