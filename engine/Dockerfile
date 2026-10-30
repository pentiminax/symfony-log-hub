# syntax=docker/dockerfile:1
FROM rust:1-bookworm AS build
WORKDIR /app
# Dependency layer: cached until Cargo.toml/Cargo.lock change.
COPY Cargo.toml Cargo.lock ./
RUN mkdir src && echo 'fn main() {}' > src/main.rs && touch src/lib.rs \
    && cargo build --release && rm -rf src
COPY migrations ./migrations
COPY src ./src
RUN touch src/main.rs src/lib.rs && cargo build --release

FROM gcr.io/distroless/cc-debian12:nonroot
COPY --from=build /app/target/release/loghub-engine /usr/local/bin/loghub-engine
EXPOSE 8080 8081
USER nonroot
ENTRYPOINT ["/usr/local/bin/loghub-engine"]
