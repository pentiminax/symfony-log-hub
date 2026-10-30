#!/bin/sh
# Run cargo inside Docker (no host toolchain). Usage: ./cargo.sh build | test | clippy ... | fmt ...
# fmt/clippy are rustup components that the rust image does not ship; install them on the fly.
exec docker run --rm -v "$(cd "$(dirname "$0")" && pwd)":/app -w /app \
  -v loghub-cargo-registry:/usr/local/cargo/registry -v loghub-engine-target:/app/target \
  ${DOCKER_ARGS:-} rust:1 sh -c 'case "$1" in fmt|clippy) rustup component add rustfmt clippy >/dev/null 2>&1;; esac; exec cargo "$@"' sh "$@"
