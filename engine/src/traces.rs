//! Trace search (internal port). A trace matches when at least one of its spans matches the
//! range and all filters; the row summarises the *whole* trace (all stored spans), so a trace
//! straddling the range edge still reports its true start, duration and counts.
//! Ordered by trace start desc with keyset pagination on `(start_time, trace_id)`.

use axum::{Json, extract::State};
use chrono::{DateTime, Utc};
use serde::Deserialize;
use serde_json::{Value, json};
use sqlx::{FromRow, QueryBuilder};
use uuid::Uuid;

use crate::{
    ApiJson, ApiPath, AppState,
    error::ApiError,
    search::{Filter, Kind, Range, Target, check_range, invalid, push_filter, push_range},
};

const MAX_LIMIT: i64 = 200;
const MAX_SPANS_PER_TRACE: i64 = 10_000;

const KIND_SQL: &str = "(CASE kind WHEN 1 THEN 'internal' WHEN 2 THEN 'server' WHEN 3 THEN 'client' \
                        WHEN 4 THEN 'producer' WHEN 5 THEN 'consumer' ELSE 'unspecified' END)";
const STATUS_SQL: &str = "(CASE status_code WHEN 1 THEN 'ok' WHEN 2 THEN 'error' ELSE 'unset' END)";

const COLUMNS: [(&str, &str, Kind); 7] = [
    ("service.name", "service_name", Kind::Text),
    ("name", "name", Kind::Text),
    ("kind", KIND_SQL, Kind::Text),
    ("status", STATUS_SQL, Kind::Text),
    ("duration", "duration_ns", Kind::Long),
    ("environment", "environment", Kind::Text),
    ("trace.id", "trace_id", Kind::Text),
];

/// OTel attribute keys contain dots, and attributes are stored as a flat map, so everything
/// after `attributes.` / `resource.` is ONE key (nested kvlist values are not filterable).
fn resolve(field: &str) -> Result<Target, ApiError> {
    if let Some((_, col, kind)) = COLUMNS.iter().find(|(f, _, _)| *f == field) {
        return Ok(Target::Column(col, *kind));
    }
    for (prefix, col) in [("attributes.", "attributes"), ("resource.", "resource")] {
        if let Some(key) = field.strip_prefix(prefix) {
            let valid = !key.is_empty()
                && key
                    .bytes()
                    .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'.' | b'-' | b'/'));
            if !valid {
                return Err(invalid(format!("Invalid field path \"{field}\".")));
            }
            return Ok(Target::Json(col, vec![key.to_owned()]));
        }
    }
    Err(invalid(format!("Unknown filterable field \"{field}\".")))
}

#[derive(Deserialize)]
struct Cursor {
    start_time: DateTime<Utc>,
    trace_id: String,
}

#[derive(Deserialize)]
pub struct SearchRequest {
    #[serde(default)]
    range: Range,
    #[serde(default)]
    filters: Vec<Filter>,
    #[serde(default = "default_limit")]
    limit: i64,
    cursor: Option<Cursor>,
}

fn default_limit() -> i64 {
    50
}

#[derive(FromRow)]
struct TraceRow {
    trace_id: String,
    start_time: DateTime<Utc>,
    duration_ns: i64,
    span_count: i64,
    error_count: i64,
    services: Vec<String>,
    root_span_id: Option<String>,
    root_name: Option<String>,
    root_service: Option<String>,
}

pub async fn search(
    State(s): State<AppState>,
    ApiPath(project_id): ApiPath<Uuid>,
    ApiJson(q): ApiJson<SearchRequest>,
) -> Result<Json<Value>, ApiError> {
    if !(1..=MAX_LIMIT).contains(&q.limit) {
        return Err(invalid(format!("limit must be between 1 and {MAX_LIMIT}")));
    }
    check_range(&q.range)?;

    let mut qb = QueryBuilder::new(
        "SELECT trace_id, min(start_time) AS start_time, \
         (extract(epoch FROM max(end_time) - min(start_time)) * 1000000000)::bigint AS duration_ns, \
         count(*) AS span_count, count(*) FILTER (WHERE status_code = 2) AS error_count, \
         array_agg(DISTINCT service_name) AS services, \
         (array_agg(span_id ORDER BY start_time) FILTER (WHERE parent_span_id IS NULL))[1] AS root_span_id, \
         (array_agg(name ORDER BY start_time) FILTER (WHERE parent_span_id IS NULL))[1] AS root_name, \
         (array_agg(service_name ORDER BY start_time) FILTER (WHERE parent_span_id IS NULL))[1] AS root_service \
         FROM spans WHERE project_id = ",
    );
    qb.push_bind(project_id)
        .push(" AND trace_id IN (SELECT trace_id FROM spans WHERE project_id = ")
        .push_bind(project_id);
    push_range(&mut qb, &q.range, "start_time");
    for f in &q.filters {
        push_filter(&mut qb, f, resolve)?;
    }
    qb.push(") GROUP BY trace_id");
    if let Some(c) = &q.cursor {
        qb.push(" HAVING (min(start_time), trace_id) < (")
            .push_bind(c.start_time)
            .push(", ")
            .push_bind(&c.trace_id)
            .push(")");
    }
    qb.push(" ORDER BY min(start_time) DESC, trace_id DESC LIMIT ")
        .push_bind(q.limit + 1);

    let mut rows: Vec<TraceRow> = qb.build_query_as().fetch_all(&s.pool).await?;
    let limit = usize::try_from(q.limit).unwrap_or(usize::MAX);
    let has_more = rows.len() > limit;
    rows.truncate(limit);
    let next_cursor = match rows.last() {
        Some(last) if has_more => json!({"start_time": last.start_time, "trace_id": last.trace_id}),
        _ => Value::Null,
    };
    let traces: Vec<Value> = rows
        .into_iter()
        .map(|r| {
            json!({
                "trace_id": r.trace_id,
                "root": r.root_span_id.map(|id| json!({
                    "span_id": id, "name": r.root_name, "service_name": r.root_service,
                })),
                "start_time": r.start_time,
                "duration_ns": r.duration_ns,
                "span_count": r.span_count,
                "error_count": r.error_count,
                "services": r.services,
            })
        })
        .collect();
    Ok(Json(
        json!({"traces": traces, "next_cursor": next_cursor, "has_more": has_more}),
    ))
}

#[derive(FromRow)]
struct SpanRow {
    trace_id: String,
    span_id: String,
    parent_span_id: Option<String>,
    name: String,
    kind: String,
    start_time: DateTime<Utc>,
    end_time: DateTime<Utc>,
    duration_ns: i64,
    status: String,
    status_message: Option<String>,
    service_name: String,
    environment: Option<String>,
    scope_name: Option<String>,
    scope_version: Option<String>,
    resource: Value,
    attributes: Value,
    events: Value,
}

/// Scoped by project: a trace id alone never crosses a project boundary.
pub async fn find(
    State(s): State<AppState>,
    ApiPath((project_id, trace_id)): ApiPath<(Uuid, String)>,
) -> Result<Json<Value>, ApiError> {
    let rows: Vec<SpanRow> = sqlx::query_as(&format!(
        "SELECT trace_id, span_id, parent_span_id, name, {KIND_SQL} AS kind, start_time, end_time, \
         duration_ns, {STATUS_SQL} AS status, status_message, service_name, environment, scope_name, \
         scope_version, resource, attributes, events FROM spans \
         WHERE project_id = $1 AND trace_id = $2 ORDER BY start_time, span_id LIMIT $3"
    ))
    .bind(project_id)
    .bind(&trace_id)
    .bind(MAX_SPANS_PER_TRACE)
    .fetch_all(&s.pool)
    .await?;
    if rows.is_empty() {
        return Err(ApiError::NotFound);
    }
    let spans: Vec<Value> = rows
        .into_iter()
        .map(|r| {
            json!({
                "trace_id": r.trace_id,
                "span_id": r.span_id,
                "parent_span_id": r.parent_span_id,
                "name": r.name,
                "kind": r.kind,
                "start_time": r.start_time,
                "end_time": r.end_time,
                "duration_ns": r.duration_ns,
                "status": {"code": r.status, "message": r.status_message},
                "service_name": r.service_name,
                "environment": r.environment,
                "scope": {"name": r.scope_name, "version": r.scope_version},
                "resource": r.resource,
                "attributes": r.attributes,
                "events": r.events,
            })
        })
        .collect();
    Ok(Json(json!({"trace_id": trace_id, "spans": spans})))
}
