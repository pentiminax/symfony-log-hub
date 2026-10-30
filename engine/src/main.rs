use std::process::ExitCode;

use loghub_engine::{AppState, config::Config, ingest_router, internal_router, migrate, retention};
use sqlx::postgres::PgPoolOptions;
use tokio::{net::TcpListener, signal};
use tracing_subscriber::EnvFilter;

#[tokio::main]
async fn main() -> ExitCode {
    tracing_subscriber::fmt()
        .with_env_filter(
            EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new("info")),
        )
        .init();

    match run().await {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            tracing::error!("fatal: {e}");
            ExitCode::FAILURE
        }
    }
}

async fn run() -> Result<(), Box<dyn std::error::Error>> {
    let config = Config::from_env()?;
    let pool = PgPoolOptions::new()
        .max_connections(10)
        .acquire_timeout(std::time::Duration::from_secs(5))
        .connect(&config.database_url)
        .await?;
    migrate(&pool).await?;

    let state = AppState::new(pool.clone(), config.limits.clone(), &config.master_key);
    let ingest = TcpListener::bind(config.ingest_addr).await?;
    let internal = TcpListener::bind(config.internal_addr).await?;
    tracing::info!(ingest = %config.ingest_addr, internal = %config.internal_addr, "listening");

    tokio::spawn(retention::run_daily(pool, config.retention_days));

    // try_join: if one listener dies, exit instead of running half-up behind a green /health.
    tokio::try_join!(
        axum::serve(ingest, ingest_router(state.clone())).with_graceful_shutdown(shutdown()),
        axum::serve(internal, internal_router(state)).with_graceful_shutdown(shutdown()),
    )?;
    Ok(())
}

async fn shutdown() {
    let ctrl_c = async {
        let _ = signal::ctrl_c().await;
    };
    #[cfg(unix)]
    let term = async {
        if let Ok(mut s) = signal::unix::signal(signal::unix::SignalKind::terminate()) {
            s.recv().await;
        }
    };
    #[cfg(not(unix))]
    let term = std::future::pending::<()>();
    tokio::select! { () = ctrl_c => {}, () = term => {} }
}
