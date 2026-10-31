use std::time::Duration;

use sqlx::PgPool;

const BATCH: i64 = 10_000;

/// Deletes logs and spans older than `retention_days`, in batches so a large backlog never holds
/// one long transaction. Returns the number of rows removed.
pub async fn purge_expired(pool: &PgPool, retention_days: u32) -> Result<u64, sqlx::Error> {
    // Run both even if the first fails, so one broken table doesn't stop the other's purge.
    let logs = purge(
        pool,
        "DELETE FROM logs WHERE id IN (
             SELECT id FROM logs WHERE \"timestamp\" < now() - make_interval(days => $1) LIMIT $2)",
        retention_days,
    )
    .await;
    let spans = purge(
        pool,
        "DELETE FROM spans WHERE ctid IN (
             SELECT ctid FROM spans WHERE start_time < now() - make_interval(days => $1) LIMIT $2)",
        retention_days,
    )
    .await;
    Ok(logs? + spans?)
}

async fn purge(pool: &PgPool, sql: &str, retention_days: u32) -> Result<u64, sqlx::Error> {
    let mut total = 0;
    loop {
        let deleted = sqlx::query(sql)
            .bind(i32::try_from(retention_days).unwrap_or(i32::MAX))
            .bind(BATCH)
            .execute(pool)
            .await?
            .rows_affected();
        total += deleted;
        if deleted == 0 {
            return Ok(total);
        }
    }
}

/// Runs the purge once at startup, then daily.
pub async fn run_daily(pool: PgPool, retention_days: u32) {
    let mut tick = tokio::time::interval(Duration::from_hours(24));
    loop {
        tick.tick().await;
        match purge_expired(&pool, retention_days).await {
            Ok(n) => tracing::info!(deleted = n, retention_days, "retention purge done"),
            Err(e) => tracing::error!(error = %e, "retention purge failed"),
        }
    }
}
