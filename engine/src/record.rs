//! Validation and mapping of one raw ingestion record. Ports `LogRecordMapper` (PHP):
//! same checks, same order, same reason strings (Symfony Validator wording included).

use chrono::{DateTime, NaiveDateTime, Utc};
use serde_json::{Map, Value};
use uuid::Uuid;

use crate::config::Limits;

const MISSING: &str = "This field is missing.";
const BLANK: &str = "This value should not be blank.";
const NOT_NULL: &str = "This value should not be null.";
const TYPE_STRING: &str = "This value should be of type string.";
const TYPE_INT: &str = "This value should be of type int.";
const TYPE_ARRAY: &str = "This value should be of type array.";
const TYPE_COLLECTION: &str = "This value should be of type array|(Traversable&ArrayAccess).";

#[derive(Debug)]
pub struct NewLog {
    pub id: Uuid,
    pub timestamp: DateTime<Utc>,
    pub severity_text: String,
    pub severity_number: i16,
    pub message: String,
    pub channel: Option<String>,
    pub service_name: String,
    pub service_version: Option<String>,
    pub environment: Option<String>,
    pub host_name: Option<String>,
    pub trace_id: Option<String>,
    pub span_id: Option<String>,
    pub resource: Value,
    pub attributes: Value,
    pub exception: Option<Value>,
}

pub fn parse(raw: &Value, limits: &Limits) -> Result<NewLog, String> {
    // A JSON list is a PHP array too: it simply has none of the required keys.
    let empty = Map::new();
    let obj = match raw {
        Value::Object(m) => m,
        Value::Array(_) => &empty,
        _ => return Err("record must be an object".into()),
    };
    // Explicit nulls on optional keys mean "absent" (spec 04.3 sample payload).
    let optional = |k: &str| obj.get(k).filter(|v| !v.is_null());

    // Structure, in the validator's declaration order; the first violation wins.
    let timestamp = required_string(obj.get("timestamp"))?;
    let (severity_text, severity_number) = severity(obj.get("severity"))?;
    let message = required_string(obj.get("message"))?;
    let resource_map = resource(obj.get("resource"))?;
    let service_name = required_string(resource_map.get("service.name"))?;
    let channel = optional("channel")
        .map(|v| v.as_str().ok_or(TYPE_STRING))
        .transpose()?;
    let attributes = match optional("attributes") {
        None => Value::Object(Map::new()),
        Some(v @ (Value::Object(_) | Value::Array(_))) => v.clone(),
        Some(_) => return Err(TYPE_ARRAY.into()),
    };
    let resource = Value::Object(resource_map.clone());

    if message.len() > limits.max_message_bytes {
        return Err(format!(
            "message exceeds max size of {} bytes",
            limits.max_message_bytes
        ));
    }
    let attributes_json = check_json("attributes", &attributes, limits)?;
    let resource_json = check_json("resource", &resource, limits)?;

    let exception = match optional("exception") {
        Some(Value::Object(e)) => {
            if let Some(Value::String(st)) = e.get("stacktrace")
                && st.len() > limits.max_stacktrace_bytes
            {
                return Err(format!(
                    "exception.stacktrace exceeds max size of {} bytes",
                    limits.max_stacktrace_bytes
                ));
            }
            match (
                e.get("type").and_then(Value::as_str),
                e.get("message").and_then(Value::as_str),
            ) {
                (Some(t), Some(m)) => Some(serde_json::json!({
                    "type": t,
                    "message": m,
                    "stacktrace": e.get("stacktrace").and_then(Value::as_str),
                })),
                _ => None,
            }
        }
        _ => None,
    };

    let timestamp = parse_timestamp(timestamp)?;
    if !(1..=24).contains(&severity_number) {
        return Err(format!(
            "Severity number must be between 1 and 24, got {severity_number}."
        ));
    }
    let severity_number = i16::try_from(severity_number).map_err(|e| e.to_string())?;

    // Beyond the PHP checks: a value that cannot be stored must reject its record, not
    // make the whole batch fail at INSERT time.
    let res_str = |k: &str| resource_map.get(k).and_then(Value::as_str);
    let environment = res_str("deployment.environment.name").or_else(|| res_str("environment"));
    let trace_id = id_field(obj.get("trace_id"), "trace_id", 32)?;
    let span_id = id_field(obj.get("span_id"), "span_id", 16)?;
    let exception_json = exception.as_ref().map(Value::to_string).unwrap_or_default();
    no_nul("message", message)?;
    for (name, s) in [
        ("attributes", &attributes_json),
        ("resource", &resource_json),
        ("exception", &exception_json),
    ] {
        if s.contains("\\u0000") {
            return Err(format!("{name} contains a NUL character"));
        }
    }

    Ok(NewLog {
        id: Uuid::now_v7(),
        timestamp,
        severity_text: fit("severity.text", Some(severity_text), 16)?.unwrap_or_default(),
        severity_number,
        message: message.to_owned(),
        channel: fit("channel", channel, 100)?,
        service_name: fit("resource.service.name", Some(service_name), 255)?.unwrap_or_default(),
        service_version: fit("resource.service.version", res_str("service.version"), 100)?,
        environment: fit("resource.deployment.environment.name", environment, 100)?,
        host_name: fit("resource.host.name", res_str("host.name"), 255)?,
        trace_id,
        span_id,
        resource,
        attributes,
        exception,
    })
}

fn is_blank(v: &Value) -> bool {
    match v {
        Value::Null | Value::Bool(false) => true,
        Value::String(s) => s.is_empty(),
        Value::Array(a) => a.is_empty(),
        Value::Object(o) => o.is_empty(),
        _ => false,
    }
}

/// NotBlank + Type(string).
fn required_string(v: Option<&Value>) -> Result<&str, String> {
    let v = v.ok_or(MISSING)?;
    if is_blank(v) {
        return Err(BLANK.into());
    }
    v.as_str().ok_or_else(|| TYPE_STRING.into())
}

fn severity(v: Option<&Value>) -> Result<(&str, i64), String> {
    let map = match v.ok_or(MISSING)? {
        Value::Object(m) => m,
        Value::Array(_) => return Err(MISSING.into()), // a list has no "text" key
        _ => return Err(TYPE_COLLECTION.into()),
    };
    let text = required_string(map.get("text"))?;
    let number = match map.get("number").ok_or(MISSING)? {
        Value::Null => return Err(NOT_NULL.into()),
        n => n.as_i64().ok_or(TYPE_INT)?,
    };
    Ok((text, number))
}

fn resource(v: Option<&Value>) -> Result<Map<String, Value>, String> {
    match v.ok_or(MISSING)? {
        Value::Object(m) => Ok(m.clone()),
        Value::Array(_) => Ok(Map::new()),
        _ => Err(TYPE_COLLECTION.into()),
    }
}

/// Serialized size and nesting-depth limits (spec 04.6). Returns the compact JSON.
fn check_json(field: &str, v: &Value, limits: &Limits) -> Result<String, String> {
    let encoded = v.to_string();
    if encoded.len() > limits.max_attributes_bytes {
        return Err(format!(
            "{field} exceeds max size of {} bytes",
            limits.max_attributes_bytes
        ));
    }
    if depth(v) > limits.max_nesting_depth {
        return Err(format!(
            "{field} exceeds max nesting depth of {}",
            limits.max_nesting_depth
        ));
    }
    Ok(encoded)
}

fn depth(v: &Value) -> usize {
    let children = match v {
        Value::Object(m) => m.values().map(depth).max(),
        Value::Array(a) => a.iter().map(depth).max(),
        _ => return 0,
    };
    1 + children.unwrap_or(0)
}

fn parse_timestamp(s: &str) -> Result<DateTime<Utc>, String> {
    let invalid = || "timestamp is not a valid date-time".to_string();
    if let Ok(dt) = DateTime::parse_from_rfc3339(s) {
        return Ok(dt.with_timezone(&Utc));
    }
    for fmt in ["%Y-%m-%dT%H:%M:%S%.f%z", "%Y-%m-%d %H:%M:%S%.f%z"] {
        if let Ok(dt) = DateTime::parse_from_str(s, fmt) {
            return Ok(dt.with_timezone(&Utc));
        }
    }
    // No zone: UTC, like PHP's default timezone in the container.
    for fmt in ["%Y-%m-%dT%H:%M:%S%.f", "%Y-%m-%d %H:%M:%S%.f"] {
        if let Ok(dt) = NaiveDateTime::parse_from_str(s, fmt) {
            return Ok(dt.and_utc());
        }
    }
    Err(invalid())
}

fn id_field(v: Option<&Value>, name: &str, max: usize) -> Result<Option<String>, String> {
    match v {
        None | Some(Value::Null) => Ok(None),
        Some(Value::String(s)) => {
            no_nul(name, s)?;
            fit(name, Some(s.as_str()), max)
        }
        Some(_) => Err(format!("{name} must be a string")),
    }
}

fn no_nul(name: &str, s: &str) -> Result<(), String> {
    if s.contains('\0') {
        return Err(format!("{name} contains a NUL character"));
    }
    Ok(())
}

fn fit<S: AsRef<str>>(name: &str, v: Option<S>, max: usize) -> Result<Option<String>, String> {
    match v {
        Some(s) if s.as_ref().chars().count() > max => {
            Err(format!("{name} exceeds max length of {max} characters"))
        }
        Some(s) => {
            no_nul(name, s.as_ref())?;
            Ok(Some(s.as_ref().to_owned()))
        }
        None => Ok(None),
    }
}

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::*;

    fn ok() -> Value {
        json!({
            "timestamp": "2026-09-09T14:45:01.231Z",
            "severity": {"text": "ERROR", "number": 17},
            "message": "boom",
            "resource": {"service.name": "api"},
            "channel": null, "attributes": null, "trace_id": null, "exception": null
        })
    }

    #[test]
    fn accepts_sample_with_explicit_nulls() {
        let log = parse(&ok(), &Limits::default()).expect("valid");
        assert_eq!(log.severity_number, 17);
        assert_eq!(log.attributes, json!({}));
    }

    #[test]
    fn rejects_with_php_reasons() {
        let mut r = ok();
        r["message"] = json!("");
        assert_eq!(parse(&r, &Limits::default()).unwrap_err(), BLANK);
        let mut r = ok();
        r["resource"] = json!({});
        assert_eq!(parse(&r, &Limits::default()).unwrap_err(), MISSING);
        let mut r = ok();
        r["severity"]["number"] = json!(30);
        assert_eq!(
            parse(&r, &Limits::default()).unwrap_err(),
            "Severity number must be between 1 and 24, got 30."
        );
    }

    #[test]
    fn enforces_depth() {
        let mut r = ok();
        r["attributes"] = json!({"a": {"b": {"c": {"d": {"e": {"f": 1}}}}}});
        assert_eq!(
            parse(&r, &Limits::default()).unwrap_err(),
            "attributes exceeds max nesting depth of 5"
        );
    }
}
