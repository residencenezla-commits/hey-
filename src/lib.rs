//! Opening-range-breakout engine, ranked on prop-firm evaluation and payout
//! expected value.
//!
//! The objective is expected value per evaluation attempt: the probability of
//! passing an evaluation, multiplied by what a funded account nets after its
//! own costs, less the price of the attempt. The one-contract signal return is
//! an input to that, never the objective itself.

pub mod backtest;
pub mod config;
pub mod data;
pub mod engine;
pub mod ev;
pub mod features;
pub mod mc;
pub mod session;
pub mod stats;
pub mod types;
