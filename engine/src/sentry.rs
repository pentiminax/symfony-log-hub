//! `POST /api/{project_id}/envelope/`: Sentry envelope ingestion, so any Sentry SDK can send
//! logs with a DSN such as `http://lh_...@host:8080/<project_id>`. Only `log` items are
//! stored; every other item type (events, transactions, sessions...) is skipped.

use axum::{
    Json,
    body::{Body, to_bytes},
    extract::{RawQuery, State},
    http::{HeaderMap, StatusCode, header},
    response::{IntoResponse, Response},
};
use chrono::{DateTime, SecondsFormat};
use serde_json::{Map, Value, json};
use uuid::Uuid;

use crate::{ApiPath, AppState, error::ApiError, ingest, otlp, record};

/// Same keys as the bundle's `AttributeRedactor`: Sentry SDKs do not redact on their own.
const SENSITIVE_KEYS: [&str; 11] = [
    "password",
    "passwd",
    "secret",
    "token",
    "access_token",
    "refresh_token",
    "authorization",
    "cookie",
    "set-cookie",
    "api_key",
    "apikey",
];

pub async fn envelope(
    State(state): State<AppState>,
    ApiPath(path_project): ApiPath<String>,
    RawQuery(query): RawQuery,
    headers: HeaderMap,
    body: Body,
) -> Result<Response, ApiError> {
    let key = sentry_key(&headers, query.as_deref()).ok_or(ApiError::InvalidApiKey)?;
    let project_id = ingest::project_for_key(&state, key).await?;
    // The DSN path carries the project; a key must not write into another one.
    if path_project != project_id.to_string() {
        return Err(ApiError::InvalidApiKey);
    }

    let max = state.limits.max_request_bytes;
    let too_large = || {
        (
            StatusCode::PAYLOAD_TOO_LARGE,
            Json(json!({"error": "request_too_large", "max_bytes": max})),
        )
            .into_response()
    };
    let Ok(mut bytes) = to_bytes(body, max).await else {
        return Ok(too_large());
    };
    match headers
        .get(header::CONTENT_ENCODING)
        .and_then(|v| v.to_str().ok())
        .map(str::to_ascii_lowercase)
        .as_deref()
    {
        None | Some("identity") => {}
        Some("gzip") => {
            let Some(out) = otlp::gunzip(&bytes, max)? else {
                return Ok(too_large());
            };
            bytes = out.into();
        }
        Some(_) => {
            return Ok((
                StatusCode::UNSUPPORTED_MEDIA_TYPE,
                Json(json!({"error": "unsupported_content_encoding", "supported": ["gzip", "identity"]})),
            )
                .into_response());
        }
    }

    let logs = log_items(&bytes).map_err(ApiError::BadRequest)?;
    if logs.len() > state.limits.max_batch_records {
        return Ok((
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(
                json!({"error": "too_many_records", "max_records": state.limits.max_batch_records}),
            ),
        )
            .into_response());
    }
    let mut accepted = Vec::with_capacity(logs.len());
    for log in &logs {
        match record::parse(&to_record(log), &state.limits) {
            Ok(l) => accepted.push(l),
            Err(reason) => tracing::debug!(%reason, "sentry log rejected"),
        }
    }
    ingest::store(&state, project_id, &accepted).await?;

    // Sentry SDKs only check the status; the id mirrors Sentry's response shape.
    Ok(Json(json!({"id": Uuid::new_v4().simple().to_string()})).into_response())
}

/// `X-Sentry-Auth: Sentry sentry_key=..., sentry_version=7` or `?sentry_key=...`.
fn sentry_key<'a>(headers: &'a HeaderMap, query: Option<&'a str>) -> Option<&'a str> {
    let from_header = headers
        .get("x-sentry-auth")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Sentry "))
        .and_then(|v| pair(v.split(','), "sentry_key"));
    from_header.or_else(|| pair(query?.split('&'), "sentry_key"))
}

fn pair<'a>(mut pairs: impl Iterator<Item = &'a str>, name: &str) -> Option<&'a str> {
    pairs.find_map(|p| {
        let (k, v) = p.trim().split_once('=')?;
        (k == name).then_some(v)
    })
}

/// The logs of every `log` item. Grammar: a header line, then items made of a header line
/// and a payload that spans `length` bytes, or runs to the next newline without one.
fn log_items(body: &[u8]) -> Result<Vec<Value>, String> {
    let invalid = |what: &str| format!("Invalid envelope: {what}.");
    let (header, mut rest) = split_line(body);
    serde_json::from_slice::<Map<String, Value>>(header).map_err(|_| invalid("header"))?;

    let mut logs = Vec::new();
    while !rest.is_empty() {
        let (line, after) = split_line(rest);
        if line.is_empty() {
            rest = after;
            continue;
        }
        let item: Map<String, Value> =
            serde_json::from_slice(line).map_err(|_| invalid("item header"))?;
        let payload;
        (payload, rest) = match item.get("length").and_then(Value::as_u64) {
            Some(len) => {
                let len = usize::try_from(len).map_err(|_| invalid("item length"))?;
                if len > after.len() || !matches!(after.get(len), None | Some(b'\n')) {
                    return Err(invalid("item length"));
                }
                (&after[..len], after.get(len + 1..).unwrap_or_default())
            }
            None => split_line(after),
        };
        if item.get("type").and_then(Value::as_str) == Some("log") {
            let mut container: Map<String, Value> =
                serde_json::from_slice(payload).map_err(|_| invalid("log payload"))?;
            match container.remove("items") {
                Some(Value::Array(items)) => logs.extend(items),
                _ => return Err(invalid("log items")),
            }
        }
    }
    Ok(logs)
}

fn split_line(bytes: &[u8]) -> (&[u8], &[u8]) {
    match bytes.iter().position(|b| *b == b'\n') {
        Some(i) => (&bytes[..i], &bytes[i + 1..]),
        None => (bytes, &[]),
    }
}

/// Maps one Sentry log onto the `/api/v1/logs` record shape, so `record::parse` applies the
/// same validation and limits. Typed attributes (`{"value", "type"}`) are flattened and
/// sensitive ones redacted before anything is stored.
fn to_record(log: &Value) -> Value {
    let mut attributes: Map<String, Value> = log
        .get("attributes")
        .and_then(Value::as_object)
        .map(|a| {
            a.iter()
                .map(|(k, v)| (k.clone(), redact(k, v.get("value").unwrap_or(v))))
                .collect()
        })
        .unwrap_or_default();

    let level = log.get("level").and_then(Value::as_str).unwrap_or_default();
    let number = log
        .get("severity_number")
        .and_then(Value::as_i64)
        .unwrap_or(match level {
            "trace" => 1,
            "debug" => 5,
            "info" => 9,
            "warn" => 13,
            "error" => 17,
            "fatal" => 21,
            _ => 0,
        });
    let text = match level {
        "warn" => "WARNING".to_owned(),
        other => other.to_ascii_uppercase(),
    };
    let timestamp = log
        .get("timestamp")
        .and_then(Value::as_f64)
        .and_then(|s| DateTime::from_timestamp_micros((s * 1e6).round() as i64))
        .map(|t| t.to_rfc3339_opts(SecondsFormat::Micros, true));

    // Sentry has no service concept: an explicit `service.name` wins, else the SDK name.
    let service = attributes
        .remove("service.name")
        .or_else(|| attributes.get("sentry.sdk.name").cloned());
    let mut resource = Map::new();
    for (to, value) in [
        ("service.name", service),
        ("service.version", attributes.remove("sentry.release")),
        (
            "deployment.environment.name",
            attributes.remove("sentry.environment"),
        ),
        ("host.name", attributes.remove("server.address")),
    ] {
        if let Some(v) = value {
            resource.insert(to.to_owned(), v);
        }
    }

    json!({
        "timestamp": timestamp,
        "severity": {"text": text, "number": number},
        "message": log.get("body"),
        "resource": resource,
        "attributes": attributes,
        "trace_id": log.get("trace_id"),
        "span_id": log.get("span_id"),
    })
}

fn redact(key: &str, value: &Value) -> Value {
    if SENSITIVE_KEYS.contains(&key.to_ascii_lowercase().as_str()) {
        return Value::from("[REDACTED]");
    }
    match value {
        Value::Object(m) => {
            Value::Object(m.iter().map(|(k, v)| (k.clone(), redact(k, v))).collect())
        }
        other => other.clone(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::Limits;

    #[test]
    fn reads_log_items_with_and_without_length() {
        let log = br#"{"items":[{"body":"a"},{"body":"b"}]}"#;
        let mut body = b"{}\n{\"type\":\"event\",\"length\":4}\n{\n\n}\n".to_vec();
        body.extend(format!("{{\"type\":\"log\",\"length\":{}}}\n", log.len()).as_bytes());
        body.extend(log);
        body.extend(b"\n{\"type\":\"log\"}\n{\"items\":[{\"body\":\"c\"}]}\n");
        let bodies: Vec<_> = log_items(&body)
            .unwrap()
            .into_iter()
            .map(|l| l["body"].clone())
            .collect();
        assert_eq!(bodies, [json!("a"), json!("b"), json!("c")]);
    }

    #[test]
    fn rejects_malformed_envelopes() {
        assert!(log_items(b"nope").is_err());
        assert!(log_items(b"{}\n{\"type\":\"log\",\"length\":99}\n{}").is_err());
        assert!(log_items(b"{}\n{\"type\":\"log\"}\n{\"items\":1}").is_err());
    }

    #[test]
    fn maps_a_sentry_log_to_a_record() {
        let log = json!({
            "timestamp": 1_793_563_200.5,
            "trace_id": "5b8efff798038103d269b633813fc60c",
            "level": "warn",
            "body": "User 42 failed to login.",
            "attributes": {
                "sentry.sdk.name": {"value": "sentry.php.symfony", "type": "string"},
                "sentry.environment": {"value": "prod", "type": "string"},
                "server.address": {"value": "api-02", "type": "string"},
                "id": {"value": 42, "type": "integer"},
                "Password": {"value": "hunter2", "type": "string"},
                "user": {"api_key": "lh_x", "name": "ada"}
            }
        });
        let parsed = record::parse(&to_record(&log), &Limits::default()).unwrap();
        assert_eq!(parsed.severity_text, "WARNING");
        assert_eq!(parsed.severity_number, 13);
        assert_eq!(parsed.service_name, "sentry.php.symfony");
        assert_eq!(parsed.environment.as_deref(), Some("prod"));
        assert_eq!(parsed.host_name.as_deref(), Some("api-02"));
        assert_eq!(
            parsed.timestamp.to_rfc3339(),
            "2026-11-01T20:00:00.500+00:00"
        );
        assert_eq!(parsed.attributes["id"], 42);
        assert_eq!(parsed.attributes["Password"], "[REDACTED]");
        assert_eq!(
            parsed.attributes["user"],
            json!({"api_key": "[REDACTED]", "name": "ada"})
        );
    }

    #[test]
    fn finds_the_key_in_the_auth_header_or_the_query() {
        let mut headers = HeaderMap::new();
        headers.insert(
            "x-sentry-auth",
            "Sentry sentry_version=7, sentry_client=sentry.php/4.35.0, sentry_key=lh_a"
                .parse()
                .unwrap(),
        );
        assert_eq!(sentry_key(&headers, None), Some("lh_a"));
        assert_eq!(
            sentry_key(&HeaderMap::new(), Some("sentry_version=7&sentry_key=lh_b")),
            Some("lh_b")
        );
        assert_eq!(sentry_key(&HeaderMap::new(), None), None);
    }
}
