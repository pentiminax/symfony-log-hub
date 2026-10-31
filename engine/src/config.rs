use std::{env, net::SocketAddr, str::FromStr};

#[derive(Debug, thiserror::Error)]
#[error("{0}")]
pub struct ConfigError(String);

/// Per-record and per-request ingestion limits (same names/defaults as the PHP app).
#[derive(Debug, Clone)]
pub struct Limits {
    pub max_request_bytes: usize,
    pub max_batch_records: usize,
    pub max_message_bytes: usize,
    pub max_attributes_bytes: usize,
    pub max_stacktrace_bytes: usize,
    pub max_nesting_depth: usize,
}

impl Default for Limits {
    fn default() -> Self {
        Self {
            max_request_bytes: 1_000_000,
            max_batch_records: 100,
            max_message_bytes: 102_400,
            max_attributes_bytes: 65_536,
            max_stacktrace_bytes: 65_536,
            max_nesting_depth: 5,
        }
    }
}

#[derive(Debug, Clone)]
pub struct Config {
    pub master_key: String,
    pub database_url: String,
    pub listen_addr: SocketAddr,
    pub retention_days: u32,
    pub limits: Limits,
}

impl Config {
    pub fn from_env() -> Result<Self, ConfigError> {
        let master_key = required("MASTER_KEY")?;
        if master_key.len() < 16 {
            return Err(ConfigError(
                "MASTER_KEY must be at least 16 characters".into(),
            ));
        }
        let d = Limits::default();
        Ok(Self {
            master_key,
            database_url: required("DATABASE_URL")?,
            listen_addr: optional(
                "LISTEN_ADDR",
                "0.0.0.0:8080".parse().map_err(|e| addr_err(&e))?,
            )?,
            retention_days: optional("RETENTION_DAYS", 14)?,
            limits: Limits {
                max_request_bytes: optional("LOGHUB_MAX_REQUEST_BYTES", d.max_request_bytes)?,
                max_batch_records: optional("LOGHUB_MAX_BATCH_RECORDS", d.max_batch_records)?,
                max_message_bytes: optional("LOGHUB_MAX_MESSAGE_BYTES", d.max_message_bytes)?,
                max_attributes_bytes: optional(
                    "LOGHUB_MAX_ATTRIBUTES_BYTES",
                    d.max_attributes_bytes,
                )?,
                max_stacktrace_bytes: optional(
                    "LOGHUB_MAX_STACKTRACE_BYTES",
                    d.max_stacktrace_bytes,
                )?,
                max_nesting_depth: optional("LOGHUB_MAX_NESTING_DEPTH", d.max_nesting_depth)?,
            },
        })
    }
}

fn addr_err(e: &std::net::AddrParseError) -> ConfigError {
    ConfigError(e.to_string())
}

fn required(name: &str) -> Result<String, ConfigError> {
    match env::var(name) {
        Ok(v) if !v.is_empty() => Ok(v),
        _ => Err(ConfigError(format!("{name} is required"))),
    }
}

fn optional<T: FromStr>(name: &str, default: T) -> Result<T, ConfigError> {
    match env::var(name) {
        Ok(v) if !v.is_empty() => v
            .parse()
            .map_err(|_| ConfigError(format!("{name} has an invalid value"))),
        _ => Ok(default),
    }
}
