//! `POST /api/v1/logs` (spec 04). Synchronous: 202 only after the batch is committed.

use axum::{
    Json,
    body::{Body, to_bytes},
    extract::State,
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
};
use serde_json::{Value, json};
use sqlx::QueryBuilder;
use uuid::Uuid;

use crate::{AppState, auth, error::ApiError, record};

/// Postgres allows 65535 bind parameters; 17 columns per row.
const ROWS_PER_INSERT: usize = 1000;

pub async fn ingest(
    State(state): State<AppState>,
    headers: HeaderMap,
    body: Body,
) -> Result<Response, ApiError> {
    let project_id = authenticate(&state, &headers).await?;
    let limits = &state.limits;

    // Any read error here is, in practice, the limit being hit.
    let bytes = match to_bytes(body, limits.max_request_bytes).await {
        Ok(b) => b,
        Err(e) => {
            tracing::debug!(error = %e, "request body read failed");
            return Ok((
                StatusCode::PAYLOAD_TOO_LARGE,
                Json(json!({"error": "request_too_large", "max_bytes": limits.max_request_bytes})),
            )
                .into_response());
        }
    };

    let payload: Value = serde_json::from_slice(&bytes).map_err(|e| {
        tracing::debug!(error = %e, "invalid JSON payload");
        ApiError::BadRequest("Request payload contains invalid JSON.".into())
    })?;
    let Value::Object(mut payload) = payload else {
        return Err(ApiError::BadRequest(
            "Request payload must be a JSON object.".into(),
        ));
    };
    let records = match payload.remove("records") {
        None => Vec::new(),
        Some(Value::Array(a)) => a,
        Some(_) => return Err(ApiError::Invalid("records must be an array".into())),
    };

    if records.len() > limits.max_batch_records {
        return Ok((
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({"error": "too_many_records", "max_records": limits.max_batch_records})),
        )
            .into_response());
    }

    let mut accepted = Vec::with_capacity(records.len());
    let mut errors = Vec::new();
    for (index, raw) in records.iter().enumerate() {
        match record::parse(raw, limits) {
            Ok(log) => accepted.push(log),
            Err(reason) => errors.push(json!({"index": index, "reason": reason})),
        }
    }

    store(&state, project_id, &accepted).await?;

    Ok((
        StatusCode::ACCEPTED,
        Json(json!({"accepted": accepted.len(), "rejected": errors.len(), "errors": errors})),
    )
        .into_response())
}

pub(crate) async fn authenticate(state: &AppState, headers: &HeaderMap) -> Result<Uuid, ApiError> {
    project_for_key(state, auth::bearer(headers).ok_or(ApiError::InvalidApiKey)?).await
}

pub(crate) async fn project_for_key(state: &AppState, key: &str) -> Result<Uuid, ApiError> {
    sqlx::query_scalar(
        "SELECT project_id FROM api_keys WHERE hashed_key = $1 AND revoked_at IS NULL",
    )
    .bind(auth::hash_key(key))
    .fetch_optional(&state.pool)
    .await?
    .ok_or(ApiError::InvalidApiKey)
}

pub(crate) async fn store(
    state: &AppState,
    project_id: Uuid,
    logs: &[record::NewLog],
) -> Result<(), sqlx::Error> {
    if logs.is_empty() {
        return Ok(());
    }
    let mut tx = state.pool.begin().await?;
    for chunk in logs.chunks(ROWS_PER_INSERT) {
        let mut qb = QueryBuilder::new(
            "INSERT INTO logs (id, project_id, \"timestamp\", severity_text, severity_number, message, \
             channel, service_name, service_version, environment, host_name, trace_id, span_id, \
             resource, attributes, exception) ",
        );
        qb.push_values(chunk, |mut b, l| {
            b.push_bind(l.id)
                .push_bind(project_id)
                .push_bind(l.timestamp)
                .push_bind(&l.severity_text)
                .push_bind(l.severity_number)
                .push_bind(&l.message)
                .push_bind(&l.channel)
                .push_bind(&l.service_name)
                .push_bind(&l.service_version)
                .push_bind(&l.environment)
                .push_bind(&l.host_name)
                .push_bind(&l.trace_id)
                .push_bind(&l.span_id)
                .push_bind(&l.resource)
                .push_bind(&l.attributes)
                .push_bind(&l.exception);
        });
        qb.build().execute(&mut *tx).await?;
    }
    tx.commit().await
}
