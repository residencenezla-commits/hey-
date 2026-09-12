use std::process::Command;

/// Stamp the build with the git revision so every CSV can name the code that
/// produced it. Falls back to "unknown" outside a git checkout.
fn main() {
    let rev = Command::new("git")
        .args(["rev-parse", "--short=12", "HEAD"])
        .output()
        .ok()
        .filter(|o| o.status.success())
        .and_then(|o| String::from_utf8(o.stdout).ok())
        .map(|s| s.trim().to_string())
        .filter(|s| !s.is_empty())
        .unwrap_or_else(|| "unknown".to_string());
    println!("cargo:rustc-env=ORB_GIT_REV={rev}");
    println!("cargo:rerun-if-changed=.git/HEAD");
}
