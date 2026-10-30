//! The web UI: `ui/dist` (built by `npm run build`) embedded in the binary.

use axum::{
    http::{HeaderValue, Method, StatusCode, Uri, header},
    response::{Html, IntoResponse, Response},
};
use rust_embed::RustEmbed;

use crate::error::ApiError;

/// `allow_missing`: the binary still compiles (and `cargo test` runs) without Node.
#[derive(RustEmbed)]
#[folder = "ui/dist"]
#[allow_missing = true]
struct Dist;

const NOT_BUILT: &str = "<!doctype html><html lang=\"en\"><meta charset=\"utf-8\"><title>LogHub</title>\
<body style=\"font-family:system-ui;margin:3rem\"><h1>UI not built</h1>\
<p>UI not built &mdash; run <code>npm run build</code> in <code>engine/ui</code>.</p></body></html>";

fn is_api(path: &str) -> bool {
    ["/v1", "/api", "/health"].iter().any(|p| {
        path.strip_prefix(p)
            .is_some_and(|r| r.is_empty() || r.starts_with('/'))
    })
}

fn file(path: &str, cache: &'static str) -> Option<Response> {
    let f = Dist::get(path)?;
    let mime = HeaderValue::from_str(f.metadata.mimetype()).ok()?;
    Some(
        (
            [
                (header::CONTENT_TYPE, mime),
                (header::CACHE_CONTROL, HeaderValue::from_static(cache)),
            ],
            f.data.into_owned(),
        )
            .into_response(),
    )
}

const SECURITY_HEADERS: [(&str, &str); 4] = [
    ("x-content-type-options", "nosniff"),
    ("x-frame-options", "DENY"),
    ("referrer-policy", "no-referrer"),
    (
        "content-security-policy",
        "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'",
    ),
];

/// Router fallback: unknown API paths are a JSON 404, everything else is the SPA.
pub async fn serve(method: Method, uri: Uri) -> Response {
    let mut res = respond(method, uri);
    for (name, value) in SECURITY_HEADERS {
        res.headers_mut().insert(
            header::HeaderName::from_static(name),
            HeaderValue::from_static(value),
        );
    }
    res
}

fn respond(method: Method, uri: Uri) -> Response {
    let path = uri.path();
    if is_api(path) || !matches!(method, Method::GET | Method::HEAD) {
        return ApiError::NotFound.into_response();
    }
    let rel = path.trim_start_matches('/');
    if rel.starts_with("assets/") {
        // Hashed filenames: safe to cache forever. A missing asset must not become index.html.
        return file(rel, "public, max-age=31536000, immutable")
            .unwrap_or_else(|| StatusCode::NOT_FOUND.into_response());
    }
    if !rel.is_empty()
        && rel != "index.html"
        && let Some(r) = file(rel, "no-cache")
    {
        return r;
    }
    file("index.html", "no-cache").unwrap_or_else(|| Html(NOT_BUILT).into_response())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn ui_responses_carry_security_headers() {
        let res = serve(Method::GET, "/".parse().unwrap()).await;
        assert_eq!(res.headers()["x-content-type-options"], "nosniff");
        assert_eq!(res.headers()["x-frame-options"], "DENY");
        assert_eq!(res.headers()["referrer-policy"], "no-referrer");
        assert!(
            res.headers()["content-security-policy"]
                .to_str()
                .unwrap()
                .contains("frame-ancestors 'none'")
        );
    }
}
