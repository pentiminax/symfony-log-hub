#![cfg_attr(test, allow(clippy::unwrap_used, clippy::expect_used))]
//! LogHub engine: log ingestion, storage and search on a dedicated PostgreSQL database.

pub mod admin;
pub mod auth;
pub mod config;
pub mod error;
pub mod ingest;
pub mod record;
pub mod retention;
pub mod search;

use axum::{
    Json, Router,
    extract::{FromRequest, FromRequestParts, State},
    http::StatusCode,
    middleware,
    response::{IntoResponse, Response},
    routing::{get, post},
};
use sha2::{Digest, Sha256};
use sqlx::PgPool;

use crate::{config::Limits, error::ApiError};

#[derive(Clone)]
pub struct AppState {
    pub pool: PgPool,
    pub limits: Limits,
    master_digest: [u8; 32],
}

impl AppState {
    pub fn new(pool: PgPool, limits: Limits, master_key: &str) -> Self {
        Self {
            pool,
            limits,
            master_digest: Sha256::digest(master_key).into(),
        }
    }
}

/// `Json` / `Path` / `Query` whose rejections use the API's JSON error shape.
#[derive(FromRequest)]
#[from_request(via(axum::Json), rejection(ApiError))]
pub struct ApiJson<T>(pub T);

#[derive(FromRequestParts)]
#[from_request(via(axum::extract::Path), rejection(ApiError))]
pub struct ApiPath<T>(pub T);

#[derive(FromRequestParts)]
#[from_request(via(axum::extract::Query), rejection(ApiError))]
pub struct ApiQuery<T>(pub T);

pub async fn migrate(pool: &PgPool) -> Result<(), sqlx::migrate::MigrateError> {
    sqlx::migrate!("./migrations").run(pool).await
}

/// Public listener: ingestion only.
pub fn ingest_router(state: AppState) -> Router {
    Router::new()
        .route("/api/v1/logs", post(ingest::ingest))
        .with_state(state)
}

/// Internal listener: `/health` is open, everything under `/v1` needs the master key.
pub fn internal_router(state: AppState) -> Router {
    let v1 = Router::new()
        .route(
            "/projects",
            post(admin::create_project).get(admin::list_projects),
        )
        .route("/projects/{project_id}", get(admin::get_project))
        .route(
            "/projects/{project_id}/keys",
            post(admin::create_key).get(admin::list_keys),
        )
        .route(
            "/projects/{project_id}/keys/{key_id}/revoke",
            post(admin::revoke_key),
        )
        .route("/projects/{project_id}/logs/search", post(search::search))
        .route("/projects/{project_id}/logs/count", post(search::count))
        .route(
            "/projects/{project_id}/logs/facets/{field}",
            get(search::facet),
        )
        .route("/projects/{project_id}/logs/{log_id}", get(search::find))
        .route_layer(middleware::from_fn_with_state(
            state.clone(),
            auth::require_master_key,
        ));

    Router::new()
        .route("/health", get(health))
        .nest("/v1", v1)
        .with_state(state)
}

async fn health(State(state): State<AppState>) -> Response {
    let ok = sqlx::query("SELECT 1").execute(&state.pool).await.is_ok();
    let status = if ok {
        StatusCode::OK
    } else {
        StatusCode::SERVICE_UNAVAILABLE
    };
    let body = serde_json::json!({
        "status": if ok { "ok" } else { "unavailable" },
        "version": env!("CARGO_PKG_VERSION"),
    });
    (status, Json(body)).into_response()
}
