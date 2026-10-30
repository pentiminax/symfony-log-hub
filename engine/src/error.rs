use axum::{
    Json,
    extract::rejection::{JsonRejection, PathRejection, QueryRejection},
    http::StatusCode,
    response::{IntoResponse, Response},
};
use serde_json::json;

#[derive(Debug, thiserror::Error)]
pub enum ApiError {
    #[error("unauthorized")]
    Unauthorized,
    #[error("invalid api key")]
    InvalidApiKey,
    #[error("not found")]
    NotFound,
    #[error("bad request: {0}")]
    BadRequest(String),
    #[error("invalid request: {0}")]
    Invalid(String),
    #[error("storage unavailable")]
    Storage(#[from] sqlx::Error),
}

impl IntoResponse for ApiError {
    fn into_response(self) -> Response {
        let (status, body) = match &self {
            Self::Unauthorized => (StatusCode::UNAUTHORIZED, json!({"error": "unauthorized"})),
            Self::InvalidApiKey => (
                StatusCode::UNAUTHORIZED,
                json!({"error": "invalid_api_key"}),
            ),
            Self::NotFound => (StatusCode::NOT_FOUND, json!({"error": "not_found"})),
            Self::BadRequest(m) => (
                StatusCode::BAD_REQUEST,
                json!({"error": "bad_request", "message": m}),
            ),
            Self::Invalid(m) => (
                StatusCode::UNPROCESSABLE_ENTITY,
                json!({"error": "invalid_request", "message": m}),
            ),
            Self::Storage(e) => {
                tracing::error!(error = %e, "storage error");
                // Connectivity problems are retryable (503); anything else is a bug (500).
                if is_unavailable(e) {
                    (
                        StatusCode::SERVICE_UNAVAILABLE,
                        json!({"error": "storage_unavailable"}),
                    )
                } else {
                    (
                        StatusCode::INTERNAL_SERVER_ERROR,
                        json!({"error": "internal_error"}),
                    )
                }
            }
        };
        (status, Json(body)).into_response()
    }
}

/// Client-side connection failures, plus server-side SQLSTATE classes 08 (connection),
/// 53 (insufficient resources, e.g. too many connections) and 57 (operator intervention,
/// e.g. the server is starting up or shutting down).
fn is_unavailable(e: &sqlx::Error) -> bool {
    match e {
        sqlx::Error::Io(_)
        | sqlx::Error::PoolTimedOut
        | sqlx::Error::PoolClosed
        | sqlx::Error::WorkerCrashed => true,
        sqlx::Error::Database(db) => db
            .code()
            .is_some_and(|c| c.starts_with("08") || c.starts_with("53") || c.starts_with("57")),
        _ => false,
    }
}

impl From<JsonRejection> for ApiError {
    fn from(r: JsonRejection) -> Self {
        match r {
            JsonRejection::JsonDataError(e) => Self::Invalid(e.body_text()),
            other => Self::BadRequest(other.body_text()),
        }
    }
}

impl From<PathRejection> for ApiError {
    fn from(r: PathRejection) -> Self {
        Self::BadRequest(r.body_text())
    }
}

impl From<QueryRejection> for ApiError {
    fn from(r: QueryRejection) -> Self {
        Self::BadRequest(r.body_text())
    }
}
