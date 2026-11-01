# AI Contribution Guidelines

Welcome, AI assistant. Follow these guidelines when contributing to this repository.

## Project Overview

LogHub is a self-hosted, Sentry-like log and trace explorer: one Rust binary (the engine) that
ingests, stores, searches, and serves its own React UI, plus a Symfony bundle that ships logs and
traces to it. Target: one organization, under 100,000 logs per day.

| Path | What it is |
| --- | --- |
| `engine/` | The product. Rust (axum, sqlx, PostgreSQL), edition 2024. Package `loghub-engine`. |
| `engine/ui/` | React 19 + Vite + Tailwind 4 + shadcn/Base UI + TanStack Query. Embedded into the binary. |
| `packages/symfony-bundle/` | PHP SDK `loghub/symfony-bundle` (`LogHub\SymfonyBundle\`, tests in `LogHub\SymfonyBundle\Tests\`). |
| `demo/` | Sample Symfony app wired to the bundle. Not part of the product. |
| `specs/` | V1 product specs. Source of truth for behavior; read the relevant one before changing a feature. |
| `ROADMAP.md` | The 0.1 plan, with priorities (P0/P1/P2). Tick items you complete. |
| `DESIGN.md` | The UI design system and its rationale. |

**Requirements:** Rust stable (or Docker), Node 24, PHP 8.4+ with Symfony 8.1 for the bundle,
PostgreSQL 16.

## General Rules

- Use American English for code, comments, documentation, commits, and branches. `ROADMAP.md`
  is written in French; keep it that way.
- Keep changes focused and reviewable. Do not refactor unrelated code.
- Treat the engine API as a security boundary. Ingestion routes (`/api/v1/logs`, `/v1/traces`)
  accept project keys (`lh_...`); every `/v1/projects/...` and `/v1/logs|traces/...` route requires
  the master key. Keep bearer checks constant-time (`subtle`) and never log keys.
- Never commit secrets. The `dev-master-key-change-me` default in `compose.yaml` is for local use
  only.
- Add dependencies only when the task requires them. Prefer an installed crate, npm package, or
  Symfony component over a new one.
- Look for an existing helper before writing one (for example `engine/ui/src/lib/` for filters,
  attributes, columns, ranges, and CSV) and share it instead of keeping private copies.

### Do Not Edit Directly

- `target/`, `node_modules/`, `vendor/`, `engine/ui/dist/`, and `var/` are build output or managed
  dependencies.
- Lock files (`Cargo.lock`, `package-lock.json`, `composer.lock`) change only through their package
  manager. Keep `engine/ui/package-lock.json` in sync with `package.json`.
- Applied migrations in `engine/migrations/`. Add a new numbered file instead.

## Architecture

```text
Symfony app
  -> Monolog LogHubHandler -> LogBuffer -> batch transport
  -> POST /api/v1/logs  (project key)

Symfony app (tracing.enabled)
  -> OpenTelemetry SDK -> POST /v1/traces  (project key, OTLP protobuf)

Engine (engine/src)
  -> ingest.rs / otlp.rs   validate, limit, normalize into records
  -> PostgreSQL            migrations run at startup
  -> search.rs / traces.rs filters, facets, histogram, trace waterfall
  -> admin.rs              projects and API keys (master key)
  -> ui.rs                 serves the embedded UI at /
  -> retention.rs          daily purge of expired data
```

### Engine (`engine/`)

- `lib.rs` builds the router and `AppState`; `main.rs` only wires config and starts the server.
- `config.rs` reads the environment: `MASTER_KEY`, `DATABASE_URL`, `LISTEN_ADDR`, `RETENTION_DAYS`,
  and the `LOGHUB_MAX_*` ingestion limits. Add new settings there and to `compose.yaml`.
- `error.rs` holds the API error type. Return it instead of building ad-hoc responses.
- `engine/ui/dist` is embedded with `rust-embed`, so a Rust build needs the UI built first
  (the `Dockerfile` does both).
- Schema changes are new files in `engine/migrations/` (`NNNN_name.sql`), applied by sqlx at startup.
  Keep them backward compatible with the previous release so upgrades stay safe.

### UI (`engine/ui/`)

- Pages in `src/`, shared components in `src/components/`, `shadcn` primitives in
  `src/components/ui/`, pure logic (and its tests) in `src/lib/`.
- State that defines a view (period, filters, columns) belongs in the URL so views can be shared.
- Follow `DESIGN.md`: density first, one accent color, `lh-` classes, tokens as CSS variables,
  severity never conveyed by color alone.

### Bundle (`packages/symfony-bundle/`)

- A library: no lock file, no Symfony app. It must work from the lowest to the highest allowed
  dependency versions (CI checks both).
- Optional integrations (OpenTelemetry, Messenger) are `suggest`ed, not required. Guard their use
  with availability checks and never import them from always-loaded code.
- Redaction (`AttributeRedactor`) runs before anything leaves the application. Do not bypass it.
- A failing or unreachable engine must never break the host application's request.

## Commands

Use the manifests and `.github/workflows/ci.yml` as the source of truth.

### Engine

Run from `engine/`. Without a local Rust toolchain, prefix with Docker: `./cargo.sh <args>`.

```bash
cargo fmt --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked
```

Tests use `#[sqlx::test]`, which creates one database per test from `DATABASE_URL`
(a superuser, for example `postgres://engine:engine@localhost:5432/engine`). Start the database
with `docker compose up -d engine_database` from the repository root.

### UI

Run from `engine/ui/`:

```bash
npm ci
npm run typecheck
npm test
npm run build
npm run dev
```

### Bundle

Run from `packages/symfony-bundle/`:

```bash
composer validate --strict
composer install
vendor/bin/phpunit
vendor/bin/phpunit --filter=test_name
```

### Full stack

```bash
docker compose up -d --build engine
```

The UI, ingestion, and API are then on http://127.0.0.1:8080. See `README.md` for the demo app.

### Before Creating a Pull Request

Run the checks for every changed area. CI runs all of them.

| Changed area | Required checks |
| --- | --- |
| `engine/src`, `engine/tests`, migrations | `cargo fmt --check`, `cargo clippy -D warnings`, `cargo test` |
| `engine/ui` | `npm run typecheck`, `npm test`, `npm run build` |
| `engine/Dockerfile` or embedded UI | `docker build engine` |
| `packages/symfony-bundle` | `composer validate --strict`, `vendor/bin/phpunit` |
| API contract between engine and bundle | Engine tests and bundle tests |
| `README.md`, `specs/`, `ROADMAP.md`, this file | `git diff --check` and a manual diff review |

## Rust Standards

`rustfmt` and `clippy -D warnings` are the source of truth.

- `unwrap()` and `expect()` are denied by lint. Propagate errors with `?`, using `thiserror` types.
  Tests may opt out locally with `#![allow(clippy::unwrap_used, clippy::expect_used)]`.
- Validate and bound every input at the boundary (size, count, nesting depth) using the
  `LOGHUB_MAX_*` limits. Respond with a precise 4xx, never panic.
- Use sqlx with bound parameters. Never build SQL from user input; dynamic filters compose
  placeholders, not values.
- Keep handlers thin; put logic in plain functions that tests can call.
- Prefer self-explanatory names over comments. Keep a comment only for a non-obvious constraint
  or decision.
- Integration tests live in `engine/tests/` and exercise the HTTP API the way a client would.
  Add or update one for every behavior change.

## TypeScript and Frontend Standards

- TypeScript strict mode and ES modules. `tsc --noEmit` must pass.
- Match the surrounding code style: `camelCase` for variables and functions, `UpperCamelCase`
  for components and types, `cn()` for class composition.
- Server data goes through TanStack Query; do not fetch in effects.
- Put testable logic in `src/lib/` and cover it with Vitest next to the file (`foo.ts` and
  `foo.test.ts`).
- Build interactive controls with the existing `components/ui` primitives and keep them
  keyboard-accessible with proper labels and `aria-*` attributes.
- Do not hardcode the engine origin; the UI is served by the engine and uses relative URLs.

## PHP Standards

- Add `declare(strict_types=1);`, use PHP 8.4 syntax, native types, constructor property promotion,
  and `readonly` for value objects.
- Follow the Symfony coding standard and best practices: PHP attributes, autowiring,
  `#[Autowire]` for parameters, bundle configuration through `Configuration` and `LogHubExtension`.
- Use Symfony components (HttpClient, Console, Uid) instead of third-party libraries.
- Handle exceptions explicitly with the types in `src/Exception/`; never add silent catches
  except where the host application must not be affected, and then record the failure.
- Console commands use `#[AsCommand]` and live in `src/Command/`.
- Every new class or behavior gets a PHPUnit test in `tests/` mirroring `src/`, with
  snake_case method names.

## Documentation and Specs

- Keep `README.md`, `engine/README.md`, `packages/symfony-bundle/README.md`, and the relevant
  `specs/` file aligned with behavior changes (API routes, environment variables, configuration keys).
- Tick finished items in `ROADMAP.md` and add new scope there rather than in code comments.
- Write concise American English with realistic, copyable examples.

## Git and Pull Requests

- Keep commits small and focused on one decision.
- Use Conventional Commits with a scope, as in the history:
  `feat(logs): ...`, `fix(engine): ...`, `docs: ...`.
- Do not commit generated output, `.env.local`, or local tool configuration.
