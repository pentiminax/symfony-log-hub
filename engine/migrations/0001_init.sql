CREATE TABLE projects (
    id          uuid PRIMARY KEY,
    name        text NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE api_keys (
    id          uuid PRIMARY KEY,
    project_id  uuid NOT NULL REFERENCES projects (id),
    -- hex SHA-256 of the plaintext key; the plaintext is never stored.
    hashed_key  char(64) NOT NULL UNIQUE,
    label       text NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    revoked_at  timestamptz
);
CREATE INDEX api_keys_project_id_idx ON api_keys (project_id);

-- No FK to projects: ingestion resolves project_id from an API key, and a per-row FK
-- check is pure overhead on the hottest insert path.
CREATE TABLE logs (
    id               uuid PRIMARY KEY,
    project_id       uuid NOT NULL,
    "timestamp"      timestamptz NOT NULL,
    severity_text    varchar(16) NOT NULL,
    severity_number  smallint NOT NULL,
    message          text NOT NULL,
    channel          varchar(100),
    service_name     varchar(255) NOT NULL,
    service_version  varchar(100),
    environment      varchar(100),
    host_name        varchar(255),
    trace_id         varchar(32),
    span_id          varchar(16),
    resource         jsonb NOT NULL,
    attributes       jsonb NOT NULL,
    exception        jsonb,
    ingested_at      timestamptz NOT NULL DEFAULT now()
);

-- Default listing + keyset pagination: ORDER BY timestamp, id within a project.
CREATE INDEX logs_project_ts_idx ON logs (project_id, "timestamp" DESC, id DESC);
CREATE INDEX logs_project_severity_ts_idx ON logs (project_id, severity_number, "timestamp" DESC);
CREATE INDEX logs_project_service_ts_idx ON logs (project_id, service_name, "timestamp" DESC);
CREATE INDEX logs_project_env_ts_idx ON logs (project_id, environment, "timestamp" DESC);
-- Retention purge scans by timestamp across projects.
CREATE INDEX logs_ts_idx ON logs ("timestamp");
-- Future trace correlation.
CREATE INDEX logs_trace_id_idx ON logs (trace_id) WHERE trace_id IS NOT NULL;
-- No GIN on attributes: filters use `#>>`, which GIN cannot serve, and it would tax every insert.
-- Add one together with the first `@>` filter.
