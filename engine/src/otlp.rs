//! `POST /v1/traces`: OTLP/HTTP trace ingestion (protobuf only, optionally gzip).
//! Span links are dropped. Timestamps are stored as timestamptz (microseconds); `duration_ns`
//! keeps the exporter's nanosecond precision.

use std::io::Read;

use axum::{
    Json,
    body::{Body, to_bytes},
    extract::State,
    http::{HeaderMap, StatusCode, header},
    response::{IntoResponse, Response},
};
use base64::{Engine, engine::general_purpose::STANDARD};
use chrono::{DateTime, SecondsFormat, Utc};
use opentelemetry_proto::tonic::{
    collector::trace::v1::{
        ExportTracePartialSuccess, ExportTraceServiceRequest, ExportTraceServiceResponse,
    },
    common::v1::{AnyValue, KeyValue, any_value::Value as Av},
    trace::v1::Span,
};
use prost::Message;
use serde_json::{Map, Value, json};
use sqlx::QueryBuilder;
use uuid::Uuid;

use crate::{AppState, error::ApiError, ingest};

const SPANS_PER_INSERT: usize = 1000;
const PROTOBUF: &str = "application/x-protobuf";

struct NewSpan {
    trace_id: String,
    span_id: String,
    parent_span_id: Option<String>,
    name: String,
    kind: i16,
    start: DateTime<Utc>,
    end: DateTime<Utc>,
    duration_ns: i64,
    status_code: i16,
    status_message: Option<String>,
    service_name: String,
    environment: Option<String>,
    scope_name: Option<String>,
    scope_version: Option<String>,
    resource: Value,
    attributes: Value,
    events: Value,
}

pub async fn export(
    State(state): State<AppState>,
    headers: HeaderMap,
    body: Body,
) -> Result<Response, ApiError> {
    let project_id = ingest::authenticate(&state, &headers).await?;
    let max = state.limits.max_request_bytes;

    let header_str = |name| headers.get(name).and_then(|v| v.to_str().ok());
    let content_type = header_str(header::CONTENT_TYPE)
        .and_then(|v| v.split(';').next())
        .map(|v| v.trim().to_ascii_lowercase());
    if content_type.as_deref() != Some(PROTOBUF) {
        return Ok(error(
            StatusCode::UNSUPPORTED_MEDIA_TYPE,
            json!({"error": "unsupported_media_type", "supported": [PROTOBUF]}),
        ));
    }
    let gzip = match header_str(header::CONTENT_ENCODING).map(str::to_ascii_lowercase) {
        None => false,
        Some(e) if e == "identity" => false,
        Some(e) if e == "gzip" => true,
        Some(_) => {
            return Ok(error(
                StatusCode::UNSUPPORTED_MEDIA_TYPE,
                json!({"error": "unsupported_content_encoding", "supported": ["gzip", "identity"]}),
            ));
        }
    };

    let too_large = || {
        error(
            StatusCode::PAYLOAD_TOO_LARGE,
            json!({"error": "request_too_large", "max_bytes": max}),
        )
    };
    let Ok(mut bytes) = to_bytes(body, max).await else {
        return Ok(too_large());
    };
    if gzip {
        let Some(out) = gunzip(&bytes, max)? else {
            return Ok(too_large());
        };
        bytes = out.into();
    }

    let request = ExportTraceServiceRequest::decode(&bytes[..]).map_err(|_| {
        ApiError::BadRequest("Body is not a valid ExportTraceServiceRequest.".into())
    })?;

    let mut spans = Vec::new();
    let mut rejected = 0i64;
    let mut first_error = None;
    for rs in &request.resource_spans {
        let resource = attributes(rs.resource.iter().flat_map(|r| &r.attributes));
        for ss in &rs.scope_spans {
            for span in &ss.spans {
                match parse(span, &resource, ss.scope.as_ref()) {
                    Ok(s) => spans.push(s),
                    Err(reason) => {
                        rejected += 1;
                        first_error.get_or_insert(reason);
                    }
                }
            }
        }
    }

    store(&state, project_id, &spans).await?;

    let partial_success = (rejected > 0).then(|| ExportTracePartialSuccess {
        rejected_spans: rejected,
        error_message: first_error.unwrap_or_default(),
    });
    let reply = ExportTraceServiceResponse { partial_success }.encode_to_vec();
    Ok(([(header::CONTENT_TYPE, PROTOBUF)], reply).into_response())
}

fn error(status: StatusCode, body: Value) -> Response {
    (status, Json(body)).into_response()
}

/// `None` when the *decompressed* size exceeds `max` (zip bomb).
pub(crate) fn gunzip(bytes: &[u8], max: usize) -> Result<Option<Vec<u8>>, ApiError> {
    let mut out = Vec::new();
    flate2::read::GzDecoder::new(bytes)
        .take(max as u64 + 1)
        .read_to_end(&mut out)
        .map_err(|_| ApiError::BadRequest("Invalid gzip body.".into()))?;
    Ok((out.len() <= max).then_some(out))
}

fn nanos_to_time(n: u64) -> Option<DateTime<Utc>> {
    DateTime::from_timestamp(
        i64::try_from(n / 1_000_000_000).ok()?,
        u32::try_from(n % 1_000_000_000).ok()?,
    )
}

fn id_hex(bytes: &[u8], len: usize) -> Option<String> {
    (bytes.len() == len && bytes.iter().any(|b| *b != 0)).then(|| hex::encode(bytes))
}

fn parse(
    s: &Span,
    resource: &Value,
    scope: Option<&opentelemetry_proto::tonic::common::v1::InstrumentationScope>,
) -> Result<NewSpan, String> {
    let trace_id = id_hex(&s.trace_id, 16).ok_or("invalid trace_id")?;
    let span_id = id_hex(&s.span_id, 8).ok_or("invalid span_id")?;
    let parent_span_id = if s.parent_span_id.is_empty() {
        None
    } else {
        Some(id_hex(&s.parent_span_id, 8).ok_or("invalid parent_span_id")?)
    };
    if s.name.is_empty() {
        return Err("empty span name".into());
    }
    if s.start_time_unix_nano == 0 || s.end_time_unix_nano < s.start_time_unix_nano {
        return Err("invalid start/end time".into());
    }
    let start = nanos_to_time(s.start_time_unix_nano).ok_or("start time out of range")?;
    let end = nanos_to_time(s.end_time_unix_nano).ok_or("end time out of range")?;
    let duration_ns = i64::try_from(s.end_time_unix_nano - s.start_time_unix_nano)
        .map_err(|_| "duration out of range")?;

    let res_str = |k: &str| resource.get(k).and_then(Value::as_str).map(str::to_owned);
    let events = s
        .events
        .iter()
        .map(|e| {
            json!({
                "name": e.name,
                "timestamp": nanos_to_time(e.time_unix_nano)
                    .map(|t| t.to_rfc3339_opts(SecondsFormat::Nanos, true)),
                "attributes": attributes(&e.attributes),
            })
        })
        .collect();
    let span = NewSpan {
        trace_id,
        span_id,
        parent_span_id,
        name: s.name.clone(),
        kind: i16::try_from(s.kind)
            .ok()
            .filter(|k| (0..=5).contains(k))
            .unwrap_or(0),
        start,
        end,
        duration_ns,
        status_code: s
            .status
            .as_ref()
            .and_then(|st| i16::try_from(st.code).ok())
            .filter(|c| (0..=2).contains(c))
            .unwrap_or(0),
        status_message: s
            .status
            .as_ref()
            .map(|st| st.message.clone())
            .filter(|m| !m.is_empty()),
        service_name: res_str("service.name").unwrap_or_else(|| "unknown_service".into()),
        environment: res_str("deployment.environment.name")
            .or_else(|| res_str("deployment.environment"))
            .or_else(|| res_str("environment")),
        scope_name: scope.map(|c| c.name.clone()).filter(|n| !n.is_empty()),
        scope_version: scope.map(|c| c.version.clone()).filter(|n| !n.is_empty()),
        resource: resource.clone(),
        attributes: attributes(&s.attributes),
        events: Value::Array(events),
    };
    // Postgres rejects NUL in text and jsonb: one such span would fail the whole batch.
    let texts = [
        Some(&span.name),
        span.status_message.as_ref(),
        span.scope_name.as_ref(),
        span.scope_version.as_ref(),
    ];
    if texts.into_iter().flatten().any(|t| t.contains('\0'))
        || [&span.resource, &span.attributes, &span.events]
            .into_iter()
            .any(has_nul)
    {
        return Err("span contains a NUL character".into());
    }
    Ok(span)
}

fn has_nul(v: &Value) -> bool {
    match v {
        Value::String(s) => s.contains('\0'),
        Value::Array(a) => a.iter().any(has_nul),
        Value::Object(o) => o.iter().any(|(k, v)| k.contains('\0') || has_nul(v)),
        _ => false,
    }
}

fn attributes<'a>(kvs: impl IntoIterator<Item = &'a KeyValue>) -> Value {
    Value::Object(
        kvs.into_iter()
            .map(|kv| (kv.key.clone(), kv.value.as_ref().map_or(Value::Null, any)))
            .collect::<Map<_, _>>(),
    )
}

/// OTLP AnyValue -> plain JSON (bytes as base64).
fn any(v: &AnyValue) -> Value {
    match &v.value {
        None => Value::Null,
        Some(Av::StringValue(s)) => json!(s),
        Some(Av::BoolValue(b)) => json!(b),
        Some(Av::IntValue(i)) => json!(i),
        Some(Av::DoubleValue(d)) => json!(d),
        Some(Av::BytesValue(b)) => json!(STANDARD.encode(b)),
        Some(Av::ArrayValue(a)) => Value::Array(a.values.iter().map(any).collect()),
        Some(Av::KvlistValue(l)) => attributes(&l.values),
    }
}

async fn store(state: &AppState, project_id: Uuid, spans: &[NewSpan]) -> Result<(), sqlx::Error> {
    if spans.is_empty() {
        return Ok(());
    }
    let mut tx = state.pool.begin().await?;
    for chunk in spans.chunks(SPANS_PER_INSERT) {
        let mut qb = QueryBuilder::new(
            "INSERT INTO spans (project_id, trace_id, span_id, parent_span_id, name, kind, start_time, \
             end_time, duration_ns, status_code, status_message, service_name, environment, scope_name, \
             scope_version, resource, attributes, events) ",
        );
        qb.push_values(chunk, |mut b, s| {
            b.push_bind(project_id)
                .push_bind(&s.trace_id)
                .push_bind(&s.span_id)
                .push_bind(&s.parent_span_id)
                .push_bind(&s.name)
                .push_bind(s.kind)
                .push_bind(s.start)
                .push_bind(s.end)
                .push_bind(s.duration_ns)
                .push_bind(s.status_code)
                .push_bind(&s.status_message)
                .push_bind(&s.service_name)
                .push_bind(&s.environment)
                .push_bind(&s.scope_name)
                .push_bind(&s.scope_version)
                .push_bind(&s.resource)
                .push_bind(&s.attributes)
                .push_bind(&s.events);
        });
        qb.push(" ON CONFLICT (project_id, trace_id, span_id) DO NOTHING");
        qb.build().execute(&mut *tx).await?;
    }
    tx.commit().await
}
