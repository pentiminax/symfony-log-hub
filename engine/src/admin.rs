//! Project and ingestion-key administration (internal port, master key).

use axum::{Json, extract::State, http::StatusCode};
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use sqlx::FromRow;
use uuid::Uuid;

use crate::{ApiJson, ApiPath, AppState, auth, error::ApiError};

#[derive(Serialize, FromRow)]
pub struct Project {
    id: Uuid,
    name: String,
    created_at: DateTime<Utc>,
}

#[derive(Serialize, FromRow)]
pub struct ApiKey {
    id: Uuid,
    project_id: Uuid,
    label: String,
    created_at: DateTime<Utc>,
    revoked_at: Option<DateTime<Utc>>,
}

#[derive(Serialize)]
pub struct CreatedKey {
    #[serde(flatten)]
    record: ApiKey,
    /// Plaintext secret, shown once; only its hash is stored.
    key: String,
}

#[derive(Deserialize)]
pub struct NewProject {
    name: String,
}

#[derive(Deserialize)]
pub struct NewKey {
    label: String,
}

fn non_blank(field: &str, v: &str) -> Result<(), ApiError> {
    if v.trim().is_empty() || v.chars().count() > 255 {
        return Err(ApiError::Invalid(format!(
            "{field} must be 1-255 characters"
        )));
    }
    Ok(())
}

pub async fn create_project(
    State(s): State<AppState>,
    ApiJson(body): ApiJson<NewProject>,
) -> Result<(StatusCode, Json<Project>), ApiError> {
    non_blank("name", &body.name)?;
    let project = sqlx::query_as(
        "INSERT INTO projects (id, name) VALUES ($1, $2) RETURNING id, name, created_at",
    )
    .bind(Uuid::now_v7())
    .bind(body.name)
    .fetch_one(&s.pool)
    .await?;
    Ok((StatusCode::CREATED, Json(project)))
}

pub async fn list_projects(State(s): State<AppState>) -> Result<Json<Vec<Project>>, ApiError> {
    let rows = sqlx::query_as("SELECT id, name, created_at FROM projects ORDER BY created_at, id")
        .fetch_all(&s.pool)
        .await?;
    Ok(Json(rows))
}

pub async fn get_project(
    State(s): State<AppState>,
    ApiPath(id): ApiPath<Uuid>,
) -> Result<Json<Project>, ApiError> {
    sqlx::query_as("SELECT id, name, created_at FROM projects WHERE id = $1")
        .bind(id)
        .fetch_optional(&s.pool)
        .await?
        .map(Json)
        .ok_or(ApiError::NotFound)
}

pub async fn create_key(
    State(s): State<AppState>,
    ApiPath(project_id): ApiPath<Uuid>,
    ApiJson(body): ApiJson<NewKey>,
) -> Result<(StatusCode, Json<CreatedKey>), ApiError> {
    non_blank("label", &body.label)?;
    let plaintext = auth::new_api_key();
    // The FK on project_id guarantees the project exists; map its violation to 404.
    let key = sqlx::query_as(
        "INSERT INTO api_keys (id, project_id, hashed_key, label) VALUES ($1, $2, $3, $4)
         RETURNING id, project_id, label, created_at, revoked_at",
    )
    .bind(Uuid::now_v7())
    .bind(project_id)
    .bind(auth::hash_key(&plaintext))
    .bind(body.label)
    .fetch_one(&s.pool)
    .await
    .map_err(|e| match &e {
        sqlx::Error::Database(db) if db.is_foreign_key_violation() => ApiError::NotFound,
        _ => e.into(),
    })?;
    Ok((
        StatusCode::CREATED,
        Json(CreatedKey {
            record: key,
            key: plaintext,
        }),
    ))
}

pub async fn list_keys(
    State(s): State<AppState>,
    ApiPath(project_id): ApiPath<Uuid>,
) -> Result<Json<Vec<ApiKey>>, ApiError> {
    let rows = sqlx::query_as(
        "SELECT id, project_id, label, created_at, revoked_at FROM api_keys
         WHERE project_id = $1 ORDER BY created_at, id",
    )
    .bind(project_id)
    .fetch_all(&s.pool)
    .await?;
    Ok(Json(rows))
}

/// Idempotent: revoking a revoked key keeps its original `revoked_at`.
pub async fn revoke_key(
    State(s): State<AppState>,
    ApiPath((project_id, key_id)): ApiPath<(Uuid, Uuid)>,
) -> Result<Json<ApiKey>, ApiError> {
    sqlx::query_as(
        "UPDATE api_keys SET revoked_at = COALESCE(revoked_at, now())
         WHERE id = $1 AND project_id = $2
         RETURNING id, project_id, label, created_at, revoked_at",
    )
    .bind(key_id)
    .bind(project_id)
    .fetch_optional(&s.pool)
    .await?
    .map(Json)
    .ok_or(ApiError::NotFound)
}
