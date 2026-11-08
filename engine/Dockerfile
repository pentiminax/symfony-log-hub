# syntax=docker/dockerfile:1
FROM node:25-alpine AS ui
WORKDIR /ui
COPY ui/package.json ui/package-lock.json ./
RUN npm ci
COPY ui ./
RUN npm run build

FROM rust:1-bookworm AS build
WORKDIR /app
# Dependency layer: cached until Cargo.toml/Cargo.lock change.
COPY Cargo.toml Cargo.lock ./
RUN mkdir src && echo 'fn main() {}' > src/main.rs && touch src/lib.rs \
    && cargo build --release && rm -rf src
COPY migrations ./migrations
COPY src ./src
# Embedded into the binary by rust-embed.
COPY --from=ui /ui/dist ./ui/dist
RUN touch src/main.rs src/lib.rs && cargo build --release

FROM gcr.io/distroless/cc-debian12:nonroot
COPY --from=build /app/target/release/loghub-engine /usr/local/bin/loghub-engine
EXPOSE 8080
USER nonroot
ENTRYPOINT ["/usr/local/bin/loghub-engine"]
