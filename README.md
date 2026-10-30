# LogHub

A simple Sentry-like log and trace explorer.

- `engine/`: the product. A single Rust binary (port 8080) that ingests logs (`POST /api/v1/logs`) and OTLP
  traces (`POST /v1/traces`) with project keys (`lh_...`), exposes the admin/search API (`/v1/projects/...`,
  master key) and serves the React UI at `/`. See [engine/README.md](engine/README.md).
- `packages/symfony-bundle/`: the PHP SDK (`loghub/symfony-bundle`): Monolog log shipping, trace correlation,
  OpenTelemetry tracing, API client and `loghub:*` commands. See [its README](packages/symfony-bundle/README.md).
- `demo/`: a sample Symfony app wired to the bundle.

## Quickstart

1. Start the engine and its database:
   ```bash
   docker compose up -d --build engine
   ```
2. Open http://127.0.0.1:8080 and sign in with the master key (dev default: `dev-master-key-change-me`;
   set `ENGINE_MASTER_KEY` for anything else).
3. Create a project, then an API key (shown once) on the Projects page.
4. Configure the bundle in your Symfony app (`composer require loghub/symfony-bundle`):
   ```yaml
   # config/packages/loghub.yaml
   loghub:
       url: '%env(LOGHUB_URL)%'                  # http://127.0.0.1:8080
       api_key: '%env(default::LOGHUB_API_KEY)%' # the lh_... key; empty = nothing shipped
       service: { name: my-app }
       tracing: { enabled: true }                # optional, needs open-telemetry/sdk + exporter-otlp
   ```
5. Run the demo app:
   ```bash
   cd demo
   echo 'LOGHUB_API_KEY=lh_...' >> .env.local
   docker compose up -d database && composer install
   bin/console messenger:setup-transports      # the Doctrine transport has auto_setup=0
   symfony serve -d            # or: php -S 127.0.0.1:8002 -t public
   bin/console messenger:consume async -vv    # in another terminal
   ```
   Open `/demo`, click the buttons, then browse logs and traces in the LogHub UI.

Tests: engine `cd engine && ./cargo.sh test` (see its README), bundle `cd packages/symfony-bundle && vendor/bin/phpunit`.
