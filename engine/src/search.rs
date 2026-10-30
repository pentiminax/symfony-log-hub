//! Log search (internal port). Ports `PostgreSqlLogSearchStore`: same field paths, same
//! operator semantics, keyset pagination on `(timestamp, id)`.

use axum::{Json, extract::State};
use chrono::{DateTime, Utc};
use serde::Deserialize;
use serde_json::{Value, json};
use sqlx::{FromRow, Postgres, QueryBuilder};
use uuid::Uuid;

use crate::{ApiPath, ApiQuery, AppState, error::ApiError};

const MAX_LIMIT: i64 = 1000;
const FACET_LIMIT: i64 = 200;
const MAX_BUCKETS: i64 = 1000;
const MAX_INTERVAL_SECONDS: i64 = 86_400;
const SELECT: &str = "SELECT id, project_id, \"timestamp\", severity_text, severity_number, message, channel, \
                      resource, attributes, trace_id, span_id, exception FROM logs";

/// Application-level field path -> (column, kind).
const COLUMNS: [(&str, &str, Kind); 11] = [
    ("timestamp", "\"timestamp\"", Kind::Timestamp),
    ("severity", "severity_number", Kind::Int),
    ("severity.text", "severity_text", Kind::Text),
    ("message", "message", Kind::Text),
    ("channel", "channel", Kind::Text),
    ("service.name", "service_name", Kind::Text),
    ("service.version", "service_version", Kind::Text),
    ("environment", "environment", Kind::Text),
    ("host.name", "host_name", Kind::Text),
    ("trace.id", "trace_id", Kind::Text),
    ("span.id", "span_id", Kind::Text),
];
const JSON_COLUMNS: [(&str, &str); 3] = [
    ("attributes.", "attributes"),
    ("resource.", "resource"),
    ("exception.", "exception"),
];
const FACETS: [&str; 3] = ["service.name", "environment", "channel"];

#[derive(Clone, Copy)]
pub(crate) enum Kind {
    Text,
    Int,
    Long,
    Timestamp,
}

pub(crate) enum Target {
    Column(&'static str, Kind),
    Json(&'static str, Vec<String>),
}

#[derive(Deserialize, Clone, Copy, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
enum Operator {
    Equals,
    NotEquals,
    Contains,
    NotContains,
    GreaterThan,
    GreaterThanOrEqual,
    LessThan,
    LessThanOrEqual,
    Exists,
    NotExists,
    Before,
    After,
    Between,
}

#[derive(Deserialize)]
pub(crate) struct Filter {
    field: String,
    operator: Operator,
    #[serde(default)]
    value: Option<Value>,
}

#[derive(Deserialize, Default)]
pub(crate) struct Range {
    from: Option<DateTime<Utc>>,
    to: Option<DateTime<Utc>>,
}

#[derive(Deserialize, Clone, Copy, Default, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
enum Direction {
    Asc,
    #[default]
    Desc,
}

#[derive(Deserialize)]
struct Sort {
    #[serde(default = "default_sort_field")]
    field: String,
    #[serde(default)]
    direction: Direction,
}

impl Default for Sort {
    fn default() -> Self {
        Self {
            field: default_sort_field(),
            direction: Direction::Desc,
        }
    }
}

fn default_sort_field() -> String {
    "timestamp".into()
}

#[derive(Deserialize)]
struct Cursor {
    timestamp: DateTime<Utc>,
    id: Uuid,
}

#[derive(Deserialize)]
pub struct SearchRequest {
    #[serde(default)]
    range: Range,
    search: Option<String>,
    #[serde(default)]
    filters: Vec<Filter>,
    #[serde(default)]
    sort: Sort,
    #[serde(default = "default_limit")]
    limit: i64,
    cursor: Option<Cursor>,
}

fn default_limit() -> i64 {
    50
}

#[derive(Deserialize)]
pub struct CountRequest {
    #[serde(flatten)]
    query: SearchRequest,
    #[serde(default = "default_max")]
    max: i64,
}

fn default_max() -> i64 {
    10_000
}

#[derive(Deserialize)]
pub struct RangeQuery {
    from: Option<DateTime<Utc>>,
    to: Option<DateTime<Utc>>,
}

#[derive(FromRow)]
struct LogRow {
    id: Uuid,
    project_id: Uuid,
    timestamp: DateTime<Utc>,
    severity_text: String,
    severity_number: i16,
    message: String,
    channel: Option<String>,
    resource: Value,
    attributes: Value,
    trace_id: Option<String>,
    span_id: Option<String>,
    exception: Option<Value>,
}

impl LogRow {
    fn into_json(self) -> Value {
        json!({
            "id": self.id,
            "project_id": self.project_id,
            "timestamp": self.timestamp,
            "severity": {"text": self.severity_text, "number": self.severity_number},
            "message": self.message,
            "channel": self.channel,
            "resource": self.resource,
            "attributes": self.attributes,
            "trace_id": self.trace_id,
            "span_id": self.span_id,
            "exception": self.exception,
        })
    }
}

pub(crate) fn invalid(msg: impl Into<String>) -> ApiError {
    ApiError::Invalid(msg.into())
}

fn resolve(field: &str) -> Result<Target, ApiError> {
    if let Some((_, col, kind)) = COLUMNS.iter().find(|(f, _, _)| *f == field) {
        return Ok(Target::Column(col, *kind));
    }
    for (prefix, col) in JSON_COLUMNS {
        if let Some(path) = field.strip_prefix(prefix) {
            let segments: Vec<String> = path.split('.').map(str::to_owned).collect();
            let valid = |s: &String| {
                !s.is_empty() && s.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
            };
            if !segments.iter().all(valid) {
                return Err(invalid(format!("Invalid field path \"{field}\".")));
            }
            return Ok(Target::Json(col, segments));
        }
    }
    Err(invalid(format!("Unknown filterable field \"{field}\".")))
}

pub(crate) fn push_range(qb: &mut QueryBuilder<'_, Postgres>, range: &Range, col: &str) {
    if let Some(from) = range.from {
        qb.push(format_args!(" AND {col} >= ")).push_bind(from);
    }
    if let Some(to) = range.to {
        qb.push(format_args!(" AND {col} <= ")).push_bind(to);
    }
}

pub(crate) fn check_range(range: &Range) -> Result<(), ApiError> {
    match (range.from, range.to) {
        (Some(f), Some(t)) if f > t => Err(invalid("range.from must not be after range.to")),
        _ => Ok(()),
    }
}

/// `FROM logs WHERE ...` with every predicate of a search but no order/cursor/limit, so a
/// count and a page describe the same result set.
/// `project_id: None` is the all-projects scope.
fn push_matching(
    qb: &mut QueryBuilder<'_, Postgres>,
    project_id: Option<Uuid>,
    q: &SearchRequest,
) -> Result<(), ApiError> {
    check_range(&q.range)?;
    qb.push(" WHERE TRUE");
    push_scope(qb, project_id);
    push_range(qb, &q.range, "\"timestamp\"");
    if let Some(search) = q.search.as_deref().filter(|s| !s.is_empty()) {
        let pattern = like_pattern(search);
        qb.push(" AND (message ILIKE ")
            .push_bind(pattern.clone())
            .push(" OR (exception #>> '{message}') ILIKE ")
            .push_bind(pattern)
            .push(")");
    }
    for f in &q.filters {
        push_filter(qb, f, resolve)?;
    }
    Ok(())
}

pub(crate) fn push_scope(qb: &mut QueryBuilder<'_, Postgres>, project_id: Option<Uuid>) {
    if let Some(id) = project_id {
        qb.push(" AND project_id = ").push_bind(id);
    }
}

fn like_pattern(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('%');
    for c in s.chars() {
        if matches!(c, '%' | '_' | '\\') {
            out.push('\\');
        }
        out.push(c);
    }
    out.push('%');
    out
}

fn push_text(qb: &mut QueryBuilder<'_, Postgres>, t: &Target) {
    match t {
        Target::Column(col, _) => {
            qb.push(*col);
        }
        Target::Json(col, path) => {
            qb.push("(");
            push_json(qb, col, path);
            qb.push(" #>> '{}')");
        }
    }
}

/// The JSONB value at `path`: the flat dotted key (`{"url.path": ..}`, what SDKs send) wins
/// over the nested path (`{"url": {"path": ..}}`).
fn push_json(qb: &mut QueryBuilder<'_, Postgres>, col: &str, path: &[String]) {
    qb.push(format_args!("COALESCE({col} -> "))
        .push_bind(path.join("."))
        .push(format_args!(", {col} #> "))
        .push_bind(path.to_vec())
        .push(")");
}

fn scalar_string(v: &Value) -> Result<String, ApiError> {
    match v {
        Value::String(s) => Ok(s.clone()),
        Value::Number(n) => Ok(n.to_string()),
        Value::Bool(b) => Ok(b.to_string()),
        _ => Err(invalid("filter value must be a string, number or boolean")),
    }
}

/// `<target> <op> <value>`. Column values are bound with the column's own type; JSONB
/// values are extracted as text (so "5" matches 5) and, for numeric/boolean values, cast
/// behind a `jsonb_typeof` guard so a differently-typed row is excluded instead of erroring.
fn push_cmp(
    qb: &mut QueryBuilder<'_, Postgres>,
    t: &Target,
    sql_op: &str,
    v: &Value,
) -> Result<(), ApiError> {
    match t {
        Target::Column(col, kind) => {
            qb.push(*col).push(format_args!(" {sql_op} "));
            match kind {
                Kind::Text => qb.push_bind(scalar_string(v)?),
                Kind::Long => qb.push_bind(
                    scalar_string(v)?
                        .parse::<i64>()
                        .map_err(|_| invalid("filter value must be an integer"))?,
                ),
                Kind::Int => qb.push_bind(
                    scalar_string(v)?
                        .parse::<i16>()
                        .map_err(|_| invalid("filter value must be an integer"))?,
                ),
                Kind::Timestamp => qb.push_bind(
                    DateTime::parse_from_rfc3339(&scalar_string(v)?)
                        .map_err(|_| invalid("filter value must be an RFC 3339 date-time"))?
                        .with_timezone(&Utc),
                ),
            };
        }
        Target::Json(col, path) => match v {
            Value::String(s) => {
                push_text(qb, t);
                qb.push(format_args!(" {sql_op} ")).push_bind(s.clone());
            }
            Value::Number(_) | Value::Bool(_) => {
                let (kind, cast) = if v.is_number() {
                    ("number", "numeric")
                } else {
                    ("boolean", "boolean")
                };
                qb.push("(CASE WHEN jsonb_typeof(");
                push_json(qb, col, path);
                qb.push(format_args!(") = '{kind}' THEN ("));
                push_json(qb, col, path);
                qb.push(format_args!(" #>> '{{}}')::{cast} END) {sql_op} "));
                qb.push_bind(scalar_string(v)?)
                    .push(format_args!("::{cast}"));
            }
            _ => return Err(invalid("filter value must be a string, number or boolean")),
        },
    }
    Ok(())
}

pub(crate) fn push_filter(
    qb: &mut QueryBuilder<'_, Postgres>,
    f: &Filter,
    resolve: fn(&str) -> Result<Target, ApiError>,
) -> Result<(), ApiError> {
    let target = resolve(&f.field)?;
    qb.push(" AND ");
    let value = || {
        f.value
            .as_ref()
            .ok_or_else(|| invalid(format!("operator requires a value for \"{}\"", f.field)))
    };
    match f.operator {
        Operator::Exists | Operator::NotExists => {
            push_text(qb, &target);
            qb.push(if f.operator == Operator::Exists {
                " IS NOT NULL"
            } else {
                " IS NULL"
            });
        }
        Operator::Contains | Operator::NotContains => {
            if matches!(
                target,
                Target::Column(_, Kind::Int | Kind::Long | Kind::Timestamp)
            ) {
                return Err(invalid(format!(
                    "contains is not supported on \"{}\"",
                    f.field
                )));
            }
            push_text(qb, &target);
            qb.push(if f.operator == Operator::Contains {
                " ILIKE "
            } else {
                " NOT ILIKE "
            })
            .push_bind(like_pattern(&scalar_string(value()?)?));
        }
        Operator::Between => {
            let [lo, hi] = value()?
                .as_array()
                .map(Vec::as_slice)
                .and_then(|a| <&[Value; 2]>::try_from(a).ok())
                .ok_or_else(|| invalid("between requires a [from, to] array"))?;
            qb.push("(");
            push_cmp(qb, &target, ">=", lo)?;
            qb.push(" AND ");
            push_cmp(qb, &target, "<=", hi)?;
            qb.push(")");
        }
        op => {
            let sql_op = match op {
                Operator::Equals => "=",
                Operator::NotEquals => "!=",
                Operator::GreaterThan | Operator::After => ">",
                Operator::GreaterThanOrEqual => ">=",
                Operator::LessThan | Operator::Before => "<",
                _ => "<=",
            };
            push_cmp(qb, &target, sql_op, value()?)?;
        }
    }
    Ok(())
}

pub async fn search(
    State(s): State<AppState>,
    ApiPath(project_id): ApiPath<Uuid>,
    crate::ApiJson(q): crate::ApiJson<SearchRequest>,
) -> Result<Json<Value>, ApiError> {
    search_in(s, Some(project_id), q).await
}

pub async fn search_all(
    State(s): State<AppState>,
    crate::ApiJson(q): crate::ApiJson<SearchRequest>,
) -> Result<Json<Value>, ApiError> {
    search_in(s, None, q).await
}

async fn search_in(
    s: AppState,
    project_id: Option<Uuid>,
    q: SearchRequest,
) -> Result<Json<Value>, ApiError> {
    if !(1..=MAX_LIMIT).contains(&q.limit) {
        return Err(invalid(format!("limit must be between 1 and {MAX_LIMIT}")));
    }
    let Target::Column(sort_col, _) = resolve(&q.sort.field)? else {
        return Err(invalid("sort field must be a column field"));
    };
    let (dir, cmp) = if q.sort.direction == Direction::Asc {
        ("ASC", ">")
    } else {
        ("DESC", "<")
    };

    let mut qb = QueryBuilder::new(SELECT);
    push_matching(&mut qb, project_id, &q)?;
    if let Some(c) = &q.cursor {
        if q.sort.field != "timestamp" {
            return Err(invalid(format!(
                "Cursor pagination requires sorting by \"timestamp\", got \"{}\".",
                q.sort.field
            )));
        }
        qb.push(format_args!(" AND (\"timestamp\", id) {cmp} ("))
            .push_bind(c.timestamp)
            .push(", ")
            .push_bind(c.id)
            .push(")");
    }
    qb.push(format_args!(" ORDER BY {sort_col} {dir}, id {dir} LIMIT "))
        .push_bind(q.limit + 1);

    let mut rows: Vec<LogRow> = qb.build_query_as().fetch_all(&s.pool).await?;
    let limit = usize::try_from(q.limit).unwrap_or(usize::MAX);
    let has_more = rows.len() > limit;
    rows.truncate(limit);
    let next_cursor = match rows.last() {
        Some(last) if has_more => json!({"timestamp": last.timestamp, "id": last.id}),
        _ => Value::Null,
    };
    let records: Vec<Value> = rows.into_iter().map(LogRow::into_json).collect();
    Ok(Json(
        json!({"records": records, "next_cursor": next_cursor, "has_more": has_more}),
    ))
}

/// Exact count below `max`, saturating at `max`.
pub async fn count(
    State(s): State<AppState>,
    ApiPath(project_id): ApiPath<Uuid>,
    crate::ApiJson(req): crate::ApiJson<CountRequest>,
) -> Result<Json<Value>, ApiError> {
    count_in(s, Some(project_id), req).await
}

pub async fn count_all(
    State(s): State<AppState>,
    crate::ApiJson(req): crate::ApiJson<CountRequest>,
) -> Result<Json<Value>, ApiError> {
    count_in(s, None, req).await
}

async fn count_in(
    s: AppState,
    project_id: Option<Uuid>,
    req: CountRequest,
) -> Result<Json<Value>, ApiError> {
    if !(1..=1_000_000).contains(&req.max) {
        return Err(invalid("max must be between 1 and 1000000"));
    }
    let mut qb = QueryBuilder::new("SELECT count(*) FROM (SELECT 1 FROM logs");
    push_matching(&mut qb, project_id, &req.query)?;
    qb.push(" LIMIT ").push_bind(req.max).push(") AS bounded");
    let count: i64 = qb.build_query_scalar().fetch_one(&s.pool).await?;
    Ok(Json(json!({"count": count})))
}

#[derive(Deserialize)]
pub struct HistogramRequest {
    #[serde(flatten)]
    query: SearchRequest,
    /// Bucket width in seconds.
    interval: i64,
}

/// Contiguous buckets of `interval` seconds over `range` (both bounds required), aligned on
/// `range.from`; empty buckets are zeros. `errors` = severity_number >= 17 (OTel ERROR+).
pub async fn histogram(
    State(s): State<AppState>,
    ApiPath(project_id): ApiPath<Uuid>,
    crate::ApiJson(req): crate::ApiJson<HistogramRequest>,
) -> Result<Json<Value>, ApiError> {
    histogram_in(s, Some(project_id), req).await
}

pub async fn histogram_all(
    State(s): State<AppState>,
    crate::ApiJson(req): crate::ApiJson<HistogramRequest>,
) -> Result<Json<Value>, ApiError> {
    histogram_in(s, None, req).await
}

async fn histogram_in(
    s: AppState,
    project_id: Option<Uuid>,
    req: HistogramRequest,
) -> Result<Json<Value>, ApiError> {
    let (Some(from), Some(to)) = (req.query.range.from, req.query.range.to) else {
        return Err(invalid("range.from and range.to are required"));
    };
    if !(1..=MAX_INTERVAL_SECONDS).contains(&req.interval) {
        return Err(invalid(format!(
            "interval must be between 1 and {MAX_INTERVAL_SECONDS} seconds"
        )));
    }
    let interval = chrono::Duration::seconds(req.interval);
    let span = (to - from).num_seconds().max(0);
    let n = (span + req.interval - 1) / req.interval;
    let n = n.max(1);
    if n > MAX_BUCKETS {
        return Err(invalid(format!(
            "range / interval would produce {n} buckets, the maximum is {MAX_BUCKETS}"
        )));
    }

    let mut qb = QueryBuilder::new("SELECT floor(extract(epoch FROM \"timestamp\" - ");
    qb.push_bind(from)
        .push(")::float8 / ")
        .push_bind(req.interval as f64)
        .push(")::bigint AS bucket, count(*) AS total, count(*) FILTER (WHERE severity_number >= 17) AS errors FROM logs");
    push_matching(&mut qb, project_id, &req.query)?;
    qb.push(" GROUP BY 1");
    let rows: Vec<(i64, i64, i64)> = qb.build_query_as().fetch_all(&s.pool).await?;

    let mut buckets: Vec<(i64, i64)> = vec![(0, 0); usize::try_from(n).unwrap_or(0)];
    for (b, total, errors) in rows {
        // A row exactly at `to` on a bucket boundary lands in the last bucket.
        let i = usize::try_from(b.clamp(0, n - 1)).unwrap_or(0);
        buckets[i].0 += total;
        buckets[i].1 += errors;
    }
    let buckets: Vec<Value> = buckets
        .into_iter()
        .enumerate()
        .map(|(i, (total, errors))| {
            let t = from + interval * i32::try_from(i).unwrap_or(0);
            json!({"t": t, "total": total, "errors": errors})
        })
        .collect();
    Ok(Json(json!({"buckets": buckets})))
}

/// Values observed for a low-cardinality field, within the optional time range.
pub async fn facet(
    State(s): State<AppState>,
    ApiPath((project_id, field)): ApiPath<(Uuid, String)>,
    ApiQuery(range): ApiQuery<RangeQuery>,
) -> Result<Json<Value>, ApiError> {
    facet_in(s, Some(project_id), field, range).await
}

pub async fn facet_all(
    State(s): State<AppState>,
    ApiPath(field): ApiPath<String>,
    ApiQuery(range): ApiQuery<RangeQuery>,
) -> Result<Json<Value>, ApiError> {
    facet_in(s, None, field, range).await
}

async fn facet_in(
    s: AppState,
    project_id: Option<Uuid>,
    field: String,
    range: RangeQuery,
) -> Result<Json<Value>, ApiError> {
    let Some(Target::Column(col, _)) = FACETS
        .contains(&field.as_str())
        .then(|| resolve(&field).ok())
        .flatten()
    else {
        return Err(invalid(format!("Field \"{field}\" is not facetable.")));
    };
    let range = Range {
        from: range.from,
        to: range.to,
    };
    check_range(&range)?;
    let mut qb = QueryBuilder::new(format!("SELECT DISTINCT {col} FROM logs WHERE TRUE"));
    push_scope(&mut qb, project_id);
    qb.push(format_args!(" AND {col} IS NOT NULL"));
    push_range(&mut qb, &range, "\"timestamp\"");
    qb.push(format_args!(" ORDER BY {col} ASC LIMIT {FACET_LIMIT}"));
    let values: Vec<String> = qb.build_query_scalar().fetch_all(&s.pool).await?;
    Ok(Json(json!({"values": values})))
}

/// Scoped by project on purpose: a log id alone must never cross a project boundary.
pub async fn find(
    State(s): State<AppState>,
    ApiPath((project_id, log_id)): ApiPath<(Uuid, Uuid)>,
) -> Result<Json<Value>, ApiError> {
    let row: Option<LogRow> =
        sqlx::query_as(&format!("{SELECT} WHERE id = $1 AND project_id = $2"))
            .bind(log_id)
            .bind(project_id)
            .fetch_optional(&s.pool)
            .await?;
    row.map(|r| Json(r.into_json())).ok_or(ApiError::NotFound)
}
