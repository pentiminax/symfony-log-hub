-- OTLP spans. No FK to projects (same reasoning as logs). Links are dropped at ingestion.
CREATE TABLE spans (
    project_id      uuid NOT NULL,
    trace_id        text NOT NULL,      -- 32 lowercase hex
    span_id         text NOT NULL,      -- 16 lowercase hex
    parent_span_id  text,               -- NULL for root spans
    name            text NOT NULL,
    kind            smallint NOT NULL,  -- OTLP SpanKind: 0 unspecified, 1 internal, 2 server, 3 client, 4 producer, 5 consumer
    start_time      timestamptz NOT NULL,
    end_time        timestamptz NOT NULL,
    duration_ns     bigint NOT NULL,    -- computed from the unix nanos (timestamptz keeps only microseconds)
    status_code     smallint NOT NULL,  -- 0 unset, 1 ok, 2 error
    status_message  text,
    service_name    text NOT NULL,
    environment     text,
    scope_name      text,
    scope_version   text,
    resource        jsonb NOT NULL,
    attributes      jsonb NOT NULL,     -- flat map, keys keep their dots ("http.request.method")
    events          jsonb NOT NULL,     -- [{name, timestamp, attributes}]
    -- Idempotent exporter retries (ON CONFLICT DO NOTHING) and the (project, trace) lookup.
    PRIMARY KEY (project_id, trace_id, span_id)
);

-- Default listing: spans of a project by start time.
CREATE INDEX spans_project_start_idx ON spans (project_id, start_time DESC);
-- Root-span listings and "which traces started in this window".
CREATE INDEX spans_project_root_start_idx ON spans (project_id, start_time DESC) WHERE parent_span_id IS NULL;
-- Service filter (the only filter every trace search is expected to use).
CREATE INDEX spans_project_service_start_idx ON spans (project_id, service_name, start_time DESC);
-- Retention purge scans by start_time across projects.
CREATE INDEX spans_start_idx ON spans (start_time);
-- No indexes on name/status/duration/JSONB: they are applied as filters on the range-bounded scan.
