use axum::{
    extract::{Request, State},
    http::{HeaderMap, header::AUTHORIZATION},
    middleware::Next,
    response::Response,
};
use sha2::{Digest, Sha256};
use subtle::ConstantTimeEq;

use crate::{AppState, error::ApiError};

/// `Authorization: Bearer <token>` (case-sensitive scheme, like the PHP authenticator).
pub fn bearer(headers: &HeaderMap) -> Option<&str> {
    headers
        .get(AUTHORIZATION)?
        .to_str()
        .ok()?
        .strip_prefix("Bearer ")
}

/// Hex SHA-256. API keys are 256-bit random secrets, so a plain deterministic digest is
/// enough and lets us look the key up by equality.
pub fn hash_key(plaintext: &str) -> String {
    hex::encode(Sha256::digest(plaintext))
}

pub fn new_api_key() -> String {
    format!("lh_{}", hex::encode(rand::random::<[u8; 32]>()))
}

pub async fn require_master_key(
    State(state): State<AppState>,
    req: Request,
    next: Next,
) -> Result<Response, ApiError> {
    let token = bearer(req.headers()).ok_or(ApiError::Unauthorized)?;
    // Compare fixed-size digests so neither content nor length leaks through timing.
    let digest = Sha256::digest(token);
    if bool::from(digest.as_slice().ct_eq(&state.master_digest)) {
        Ok(next.run(req).await)
    } else {
        Err(ApiError::Unauthorized)
    }
}
