#![cfg_attr(test, allow(clippy::unwrap_used, clippy::expect_used))]
//! LogHub engine: log ingestion, storage and search on a dedicated PostgreSQL database.

pub mod admin;
pub mod auth;
pub mod config;
pub mod error;
pub mod ingest;
pub mod otlp;
pub mod record;
pub mod retention;
pub mod search;
pub mod traces;
pub mod ui;

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

/// One router, auth per route: ingestion routes check the project key themselves,
/// `/v1/projects/...` is behind the master-key layer, `/health` and the UI are open.
pub fn router(state: AppState) -> Router {
    let admin = Router::new()
        .route(
            "/v1/projects",
            post(admin::create_project).get(admin::list_projects),
        )
        .route("/v1/projects/{project_id}", get(admin::get_project))
        .route(
            "/v1/projects/{project_id}/keys",
            post(admin::create_key).get(admin::list_keys),
        )
        .route(
            "/v1/projects/{project_id}/keys/{key_id}/revoke",
            post(admin::revoke_key),
        )
        .route(
            "/v1/projects/{project_id}/logs/search",
            post(search::search),
        )
        .route("/v1/projects/{project_id}/logs/count", post(search::count))
        .route(
            "/v1/projects/{project_id}/logs/histogram",
            post(search::histogram),
        )
        .route("/v1/logs/search", post(search::search_all))
        .route("/v1/logs/count", post(search::count_all))
        .route("/v1/logs/histogram", post(search::histogram_all))
        .route("/v1/logs/facets/{field}", get(search::facet_all))
        .route("/v1/traces/search", post(traces::search_all))
        .route(
            "/v1/projects/{project_id}/logs/facets/{field}",
            get(search::facet),
        )
        .route("/v1/projects/{project_id}/logs/{log_id}", get(search::find))
        .route(
            "/v1/projects/{project_id}/traces/search",
            post(traces::search),
        )
        .route(
            "/v1/projects/{project_id}/traces/{trace_id}",
            get(traces::find),
        )
        .route_layer(middleware::from_fn_with_state(
            state.clone(),
            auth::require_master_key,
        ));

    Router::new()
        .route("/api/v1/logs", post(ingest::ingest))
        .route("/v1/traces", post(otlp::export))
        .route("/health", get(health))
        .merge(admin)
        .fallback(ui::serve)
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
