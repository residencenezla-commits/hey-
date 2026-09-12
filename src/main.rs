use clap::Parser;
use orb_vol::config::Args;

fn main() {
    orb_vol::engine::run(&Args::parse());
}
