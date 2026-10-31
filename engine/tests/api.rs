//! HTTP-level tests against a real PostgreSQL (`DATABASE_URL`, e.g.
//! postgres://engine:engine@localhost:5432/engine). `#[sqlx::test]` gives each test its own
//! migrated database and drops it when the test passes.
#![allow(clippy::unwrap_used, clippy::expect_used)]

use loghub_engine::{AppState, config::Limits, retention, router};
use reqwest::{Client, Method, StatusCode};
use serde_json::{Value, json};
use sqlx::PgPool;

const MASTER: &str = "test-master-key-0123456789";

struct App {
    base: String,
    pool: PgPool,
    http: Client,
}

async fn app(pool: PgPool) -> App {
    app_with(pool, Limits::default()).await
}

async fn app_with(pool: PgPool, limits: Limits) -> App {
    let state = AppState::new(pool.clone(), limits, MASTER);
    let l = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let base = format!("http://{}", l.local_addr().unwrap());
    let app = router(state);
    tokio::spawn(async move { axum::serve(l, app).await });
    App {
        base,
        pool,
        http: Client::new(),
    }
}

impl App {
    async fn admin(&self, method: Method, path: &str, body: Option<Value>) -> (StatusCode, Value) {
        let mut req = self
            .http
            .request(method, format!("{}{path}", self.base))
            .bearer_auth(MASTER);
        if let Some(b) = body {
            req = req.json(&b);
        }
        let res = req.send().await.unwrap();
        (res.status(), res.json().await.unwrap_or(Value::Null))
    }

    /// Creates a project with one key; returns (project_id, plaintext key).
    async fn project(&self) -> (String, String) {
        let (_, p) = self
            .admin(Method::POST, "/v1/projects", Some(json!({"name": "demo"})))
            .await;
        let id = p["id"].as_str().unwrap().to_owned();
        let (_, k) = self
            .admin(
                Method::POST,
                &format!("/v1/projects/{id}/keys"),
                Some(json!({"label": "ci"})),
            )
            .await;
        (id, k["key"].as_str().unwrap().to_owned())
    }

    async fn post_logs(&self, key: Option<&str>, body: Value) -> (StatusCode, Value) {
        let mut req = self
            .http
            .post(format!("{}/api/v1/logs", self.base))
            .json(&body);
        if let Some(k) = key {
            req = req.bearer_auth(k);
        }
        let res = req.send().await.unwrap();
        (res.status(), res.json().await.unwrap_or(Value::Null))
    }

    async fn search(&self, project: &str, body: Value) -> Value {
        let (status, v) = self
            .admin(
                Method::POST,
                &format!("/v1/projects/{project}/logs/search"),
                Some(body),
            )
            .await;
        assert_eq!(status, StatusCode::OK, "{v}");
        v
    }

    async fn messages(&self, project: &str, body: Value) -> Vec<String> {
        self.search(project, body).await["records"]
            .as_array()
            .unwrap()
            .iter()
            .map(|r| r["message"].as_str().unwrap().to_owned())
            .collect()
    }
}

fn rec(ts: &str, sev: i64, msg: &str, service: &str, attrs: Value) -> Value {
    json!({
        "timestamp": ts,
        "severity": {"text": if sev >= 17 { "ERROR" } else { "INFO" }, "number": sev},
        "message": msg,
        "channel": "app",
        "resource": {"service.name": service, "deployment.environment.name": "prod"},
        "attributes": attrs,
    })
}

async fn seeded(pool: PgPool) -> (App, String) {
    let app = app(pool).await;
    let (project, key) = app.project().await;
    let mut e = rec(
        "2026-09-05T10:00:04Z",
        21,
        "e fatal",
        "billing",
        json!({"payment_id": 7, "retry": true, "http.status_code": 503, "url.path": "/pay"}),
    );
    e["exception"] =
        json!({"type": "RuntimeException", "message": "Provider unavailable", "stacktrace": "#0"});
    let (status, body) = app
        .post_logs(
            Some(&key),
            json!({"records": [
                rec("2026-09-05T10:00:00Z", 9, "a info", "billing", json!({"payment_id": 1, "provider": "stripe", "customer": {"country": "FR"}})),
                rec("2026-09-05T10:00:01Z", 9, "b 100% done_ok", "web", json!({"payment_id": "1", "retry": false})),
                rec("2026-09-05T10:00:02Z", 17, "c error", "billing", json!({"payment_id": 20})),
                rec("2026-09-05T10:00:03Z", 13, "d warn", "web", json!({})),
                e,
            ]}),
        )
        .await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    assert_eq!(body["accepted"], 5);
    (app, project)
}

#[sqlx::test]
async fn health_reports_status_and_version(pool: PgPool) {
    let app = app(pool).await;
    let res = app
        .http
        .get(format!("{}/health", app.base))
        .send()
        .await
        .unwrap();
    assert_eq!(res.status(), StatusCode::OK);
    let v: Value = res.json().await.unwrap();
    assert_eq!(
        v,
        json!({"status": "ok", "version": env!("CARGO_PKG_VERSION")})
    );
}

#[sqlx::test]
async fn admin_rejects_missing_or_wrong_master_key(pool: PgPool) {
    let app = app(pool).await;
    let url = format!("{}/v1/projects", app.base);
    assert_eq!(
        app.http.get(&url).send().await.unwrap().status(),
        StatusCode::UNAUTHORIZED
    );
    assert_eq!(
        app.http
            .get(&url)
            .bearer_auth("nope")
            .send()
            .await
            .unwrap()
            .status(),
        StatusCode::UNAUTHORIZED
    );
    let basic = app
        .http
        .get(&url)
        .header("Authorization", format!("Basic {MASTER}"))
        .send()
        .await
        .unwrap();
    assert_eq!(basic.status(), StatusCode::UNAUTHORIZED);
    assert_eq!(
        app.http
            .get(&url)
            .bearer_auth(MASTER)
            .send()
            .await
            .unwrap()
            .status(),
        StatusCode::OK
    );
    // A project key is not a master key.
    let (_, key) = app.project().await;
    let res = app.http.get(&url).bearer_auth(key).send().await.unwrap();
    assert_eq!(res.status(), StatusCode::UNAUTHORIZED);
}

#[sqlx::test]
async fn master_key_does_not_ingest(pool: PgPool) {
    let app = app(pool).await;
    let (s, v) = app.post_logs(Some(MASTER), json!({"records": []})).await;
    assert_eq!(s, StatusCode::UNAUTHORIZED);
    assert_eq!(v["error"], "invalid_api_key");
}

#[sqlx::test]
async fn otlp_route_uses_project_key_not_master_layer(pool: PgPool) {
    let app = app(pool).await;
    let (_, key) = app.project().await;
    let post = |token: &str| {
        app.http
            .post(format!("{}/v1/traces", app.base))
            .header("content-type", "application/x-protobuf")
            .bearer_auth(token.to_owned())
            .send()
    };
    // An empty protobuf body is a valid, empty export request.
    assert_eq!(post(&key).await.unwrap().status(), StatusCode::OK);
    let res = post(MASTER).await.unwrap();
    assert_eq!(res.status(), StatusCode::UNAUTHORIZED);
    assert_eq!(
        res.json::<Value>().await.unwrap()["error"],
        "invalid_api_key"
    );
}

#[sqlx::test]
async fn unknown_api_paths_are_json_404_and_spa_paths_are_html(pool: PgPool) {
    let app = app(pool).await;
    for path in ["/v1/nope", "/api/nope", "/health/x"] {
        let res = app
            .http
            .get(format!("{}{path}", app.base))
            .send()
            .await
            .unwrap();
        assert_eq!(res.status(), StatusCode::NOT_FOUND, "{path}");
        assert_eq!(res.json::<Value>().await.unwrap()["error"], "not_found");
    }
    for path in ["/", "/projects/abc"] {
        let res = app
            .http
            .get(format!("{}{path}", app.base))
            .send()
            .await
            .unwrap();
        assert_eq!(res.status(), StatusCode::OK, "{path}");
        assert!(
            res.headers()["content-type"]
                .to_str()
                .unwrap()
                .starts_with("text/html")
        );
        assert!(res.text().await.unwrap().contains("<html"));
    }
    // A missing hashed asset must 404, not fall back to index.html.
    let res = app
        .http
        .get(format!("{}/assets/missing-0000.js", app.base))
        .send()
        .await
        .unwrap();
    assert_eq!(res.status(), StatusCode::NOT_FOUND);
}

#[sqlx::test]
async fn project_and_key_lifecycle(pool: PgPool) {
    let app = app(pool).await;
    let (s, p) = app
        .admin(Method::POST, "/v1/projects", Some(json!({"name": "shop"})))
        .await;
    assert_eq!(s, StatusCode::CREATED);
    let id = p["id"].as_str().unwrap();
    assert_eq!(
        app.admin(Method::GET, &format!("/v1/projects/{id}"), None)
            .await
            .1["name"],
        "shop"
    );
    assert_eq!(
        app.admin(Method::GET, "/v1/projects", None)
            .await
            .1
            .as_array()
            .unwrap()
            .len(),
        1
    );
    assert_eq!(
        app.admin(Method::POST, "/v1/projects", Some(json!({"name": " "})))
            .await
            .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    assert_eq!(
        app.admin(
            Method::GET,
            &format!("/v1/projects/{}", uuid::Uuid::new_v4()),
            None
        )
        .await
        .0,
        StatusCode::NOT_FOUND
    );
    assert_eq!(
        app.admin(Method::GET, "/v1/projects/not-a-uuid", None)
            .await
            .0,
        StatusCode::BAD_REQUEST
    );

    let (s, k) = app
        .admin(
            Method::POST,
            &format!("/v1/projects/{id}/keys"),
            Some(json!({"label": "prod"})),
        )
        .await;
    assert_eq!(s, StatusCode::CREATED);
    let plaintext = k["key"].as_str().unwrap();
    assert!(plaintext.starts_with("lh_") && plaintext.len() == 67);
    let key_id = k["id"].as_str().unwrap();

    let (_, list) = app
        .admin(Method::GET, &format!("/v1/projects/{id}/keys"), None)
        .await;
    assert_eq!(list.as_array().unwrap().len(), 1);
    assert!(list[0].get("key").is_none(), "plaintext must not be listed");
    let stored: String = sqlx::query_scalar("SELECT hashed_key FROM api_keys")
        .fetch_one(&app.pool)
        .await
        .unwrap();
    assert_ne!(stored, plaintext);

    let body = json!({"records": [rec("2026-09-05T10:00:00Z", 9, "x", "api", json!({}))]});
    assert_eq!(
        app.post_logs(Some(plaintext), body.clone()).await.0,
        StatusCode::ACCEPTED
    );

    let revoke = format!("/v1/projects/{id}/keys/{key_id}/revoke");
    let (s, first) = app.admin(Method::POST, &revoke, None).await;
    assert_eq!(s, StatusCode::OK);
    assert!(first["revoked_at"].is_string());
    let (_, again) = app.admin(Method::POST, &revoke, None).await;
    assert_eq!(
        again["revoked_at"], first["revoked_at"],
        "revocation is idempotent"
    );
    let (s, err) = app.post_logs(Some(plaintext), body).await;
    assert_eq!(
        (s, err),
        (
            StatusCode::UNAUTHORIZED,
            json!({"error": "invalid_api_key"})
        )
    );

    let missing = format!("/v1/projects/{}/keys", uuid::Uuid::new_v4());
    assert_eq!(
        app.admin(Method::POST, &missing, Some(json!({"label": "x"})))
            .await
            .0,
        StatusCode::NOT_FOUND
    );
}

#[sqlx::test]
async fn ingestion_rejects_missing_or_unknown_keys(pool: PgPool) {
    let app = app(pool).await;
    let body = json!({"records": []});
    for key in [None, Some("lh_unknown")] {
        let (s, v) = app.post_logs(key, body.clone()).await;
        assert_eq!(
            (s, v),
            (
                StatusCode::UNAUTHORIZED,
                json!({"error": "invalid_api_key"})
            )
        );
    }
    let res = app
        .http
        .post(format!("{}/api/v1/logs", app.base))
        .header("Authorization", "lh_x")
        .json(&body)
        .send()
        .await
        .unwrap();
    assert_eq!(res.status(), StatusCode::UNAUTHORIZED);
}

#[sqlx::test]
async fn ingestion_accepts_batch_and_reports_partial_rejection(pool: PgPool) {
    let app = app(pool).await;
    let (project, key) = app.project().await;
    let mut with_trace = rec(
        "2026-09-05T10:00:00.123456Z",
        9,
        "traced",
        "api",
        json!(null),
    );
    with_trace["trace_id"] = json!("0af7651916cd43dd8448eb211c80319c");
    with_trace["span_id"] = json!("b7ad6b7169203331");
    with_trace["project_id"] = json!(uuid::Uuid::new_v4()); // never trusted
    let mut no_service = rec("2026-09-05T10:00:01Z", 9, "no service", "api", json!({}));
    no_service["resource"] = json!({});
    let mut bad_sev = rec("2026-09-05T10:00:02Z", 9, "bad sev", "api", json!({}));
    bad_sev["severity"]["number"] = json!(99);
    let mut bad_ts = rec("not a date", 9, "bad ts", "api", json!({}));
    bad_ts["channel"] = Value::Null;

    let (s, body) = app
        .post_logs(
            Some(&key),
            json!({"records": [with_trace, no_service, bad_sev, "nope", bad_ts]}),
        )
        .await;
    assert_eq!(s, StatusCode::ACCEPTED);
    assert_eq!(
        body,
        json!({"accepted": 1, "rejected": 4, "errors": [
            {"index": 1, "reason": "This field is missing."},
            {"index": 2, "reason": "Severity number must be between 1 and 24, got 99."},
            {"index": 3, "reason": "record must be an object"},
            {"index": 4, "reason": "timestamp is not a valid date-time"},
        ]})
    );

    let found = app.search(&project, json!({})).await;
    let r = &found["records"][0];
    assert_eq!(
        r["project_id"], project,
        "project comes from the key, not the payload"
    );
    assert_eq!(r["timestamp"], "2026-09-05T10:00:00.123456Z");
    assert_eq!(r["trace_id"], "0af7651916cd43dd8448eb211c80319c");
    assert_eq!(r["attributes"], json!({}));
    assert_eq!(r["resource"]["service.name"], "api");
}

#[sqlx::test]
async fn ingestion_enforces_request_and_batch_limits(pool: PgPool) {
    let app = app_with(
        pool,
        Limits {
            max_request_bytes: 400,
            max_batch_records: 2,
            ..Limits::default()
        },
    )
    .await;
    let (_, key) = app.project().await;
    let one = rec("2026-09-05T10:00:00Z", 9, "x", "api", json!({}));

    let (s, v) = app
        .post_logs(
            Some(&key),
            json!({"records": [one.clone(), one.clone(), one.clone()]}),
        )
        .await;
    assert_eq!(
        (s, v),
        (
            StatusCode::PAYLOAD_TOO_LARGE,
            json!({"error": "request_too_large", "max_bytes": 400})
        )
    );

    let tiny = json!({"records": [{}, {}, {}]});
    let (s, v) = app.post_logs(Some(&key), tiny).await;
    assert_eq!(
        (s, v),
        (
            StatusCode::UNPROCESSABLE_ENTITY,
            json!({"error": "too_many_records", "max_records": 2})
        )
    );

    let res = app
        .http
        .post(format!("{}/api/v1/logs", app.base))
        .bearer_auth(&key)
        .body("{oops")
        .send()
        .await
        .unwrap();
    assert_eq!(res.status(), StatusCode::BAD_REQUEST);
    let (s, _) = app.post_logs(Some(&key), json!({"records": "x"})).await;
    assert_eq!(s, StatusCode::UNPROCESSABLE_ENTITY);
}

#[sqlx::test]
async fn ingestion_enforces_per_record_limits(pool: PgPool) {
    let app = app_with(
        pool,
        Limits {
            max_message_bytes: 5,
            ..Limits::default()
        },
    )
    .await;
    let (_, key) = app.project().await;
    let long = rec("2026-09-05T10:00:00Z", 9, "too long", "api", json!({}));
    let mut nul = rec(
        "2026-09-05T10:00:00Z",
        9,
        "ok",
        "api",
        json!({"k": "a\u{0}b"}),
    );
    nul["channel"] = json!("c");
    let (_, v) = app
        .post_logs(Some(&key), json!({"records": [long, nul]}))
        .await;
    assert_eq!(
        v["errors"][0]["reason"],
        "message exceeds max size of 5 bytes"
    );
    assert_eq!(
        v["errors"][1]["reason"],
        "attributes contains a NUL character"
    );
}

#[sqlx::test]
async fn search_filters_operators_and_text(pool: PgPool) {
    let (app, p) = seeded(pool).await;
    let q = |filters: Value| json!({"filters": filters, "sort": {"direction": "asc"}});
    let f = |field: &str, op: &str, value: Value| json!({"field": field, "operator": op, "value": value});

    assert_eq!(
        app.messages(&p, q(json!([f("service.name", "equals", json!("web"))])))
            .await,
        ["b 100% done_ok", "d warn"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f("service.name", "not_equals", json!("web"))]))
        )
        .await,
        ["a info", "c error", "e fatal"]
    );
    assert_eq!(
        app.messages(&p, q(json!([f("environment", "equals", json!("prod"))])))
            .await
            .len(),
        5
    );
    // contains escapes LIKE wildcards: "%" and "_" are literal.
    assert_eq!(
        app.messages(&p, q(json!([f("message", "contains", json!("100%"))])))
            .await,
        ["b 100% done_ok"]
    );
    assert_eq!(
        app.messages(&p, q(json!([f("message", "contains", json!("e_o"))])))
            .await,
        ["b 100% done_ok"]
    );
    assert_eq!(
        app.messages(&p, q(json!([f("message", "not_contains", json!("e"))])))
            .await,
        ["a info", "d warn"]
    );
    // severity is numeric; between is inclusive.
    assert_eq!(
        app.messages(&p, q(json!([f("severity", "between", json!([9, 13]))])))
            .await
            .len(),
        3
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f("severity", "greater_than_or_equal", json!(17))]))
        )
        .await,
        ["c error", "e fatal"]
    );
    assert_eq!(
        app.messages(&p, q(json!([f("severity", "equals", json!("13"))])))
            .await,
        ["d warn"]
    );
    assert_eq!(
        app.messages(&p, q(json!([f("severity.text", "equals", json!("ERROR"))])))
            .await
            .len(),
        2
    );
    // JSON paths: numbers compare numerically, strings as text, booleans as booleans.
    assert_eq!(
        app.messages(
            &p,
            q(json!([f(
                "attributes.payment_id",
                "greater_than",
                json!(5)
            )]))
        )
        .await,
        ["c error", "e fatal"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f("attributes.payment_id", "equals", json!(1))]))
        )
        .await,
        ["a info"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f("attributes.payment_id", "equals", json!("1"))]))
        )
        .await,
        ["a info", "b 100% done_ok"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f(
                "attributes.payment_id",
                "between",
                json!([2, 10])
            )]))
        )
        .await,
        ["e fatal"]
    );
    assert_eq!(
        app.messages(&p, q(json!([f("attributes.retry", "equals", json!(true))])))
            .await,
        ["e fatal"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f(
                "attributes.customer.country",
                "equals",
                json!("FR")
            )]))
        )
        .await,
        ["a info"]
    );
    // SDKs send flat dotted keys ({"url.path": ..}): the same field syntax finds them.
    assert_eq!(
        app.messages(
            &p,
            q(json!([
                f("attributes.url.path", "equals", json!("/pay")),
                f(
                    "attributes.http.status_code",
                    "greater_than_or_equal",
                    json!(500)
                )
            ]))
        )
        .await,
        ["e fatal"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f("attributes.provider", "exists", Value::Null)]))
        )
        .await,
        ["a info"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f("attributes.provider", "not_exists", Value::Null)]))
        )
        .await
        .len(),
        4
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f(
                "exception.type",
                "equals",
                json!("RuntimeException")
            )]))
        )
        .await,
        ["e fatal"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f(
                "timestamp",
                "after",
                json!("2026-09-05T10:00:02Z")
            )]))
        )
        .await,
        ["d warn", "e fatal"]
    );
    assert_eq!(
        app.messages(
            &p,
            q(json!([f("service.name", "in", json!(["web", "billing"]))]))
        )
        .await
        .len(),
        5
    );
    assert_eq!(
        app.messages(&p, q(json!([f("service.name", "not_in", json!(["web"]))])))
            .await,
        ["a info", "c error", "e fatal"]
    );
    // Several filters AND together.
    let both = q(json!([
        f("service.name", "equals", json!("billing")),
        f("severity", "less_than", json!(20))
    ]));
    assert_eq!(app.messages(&p, both).await, ["a info", "c error"]);
    // Global search hits message and exception message.
    assert_eq!(
        app.messages(&p, json!({"search": "unavailable"})).await,
        ["e fatal"]
    );
    assert_eq!(
        app.messages(&p, json!({"search": "WARN"})).await,
        ["d warn"]
    );

    // Invalid queries are 422s, never SQL errors.
    for bad in [
        f("nope", "equals", json!("x")),
        f("attributes.a'b", "equals", json!("x")),
        f("severity", "equals", json!("abc")),
        f("message", "equals", Value::Null),
        f("service.name", "in", json!([])),
        f("service.name", "not_in", json!("web")),
        f("timestamp", "after", json!("yesterday")),
    ] {
        let (s, _) = app
            .admin(
                Method::POST,
                &format!("/v1/projects/{p}/logs/search"),
                Some(q(json!([bad]))),
            )
            .await;
        assert_eq!(s, StatusCode::UNPROCESSABLE_ENTITY);
    }
}

#[sqlx::test]
async fn search_sort_range_and_pagination(pool: PgPool) {
    let (app, p) = seeded(pool).await;
    assert_eq!(
        app.messages(&p, json!({})).await[0],
        "e fatal",
        "default is newest first"
    );
    assert_eq!(
        app.messages(&p, json!({"sort": {"direction": "asc"}}))
            .await[0],
        "a info"
    );
    assert_eq!(
        app.messages(
            &p,
            json!({"sort": {"field": "severity", "direction": "desc"}, "limit": 1})
        )
        .await,
        ["e fatal"]
    );

    let range = json!({"range": {"from": "2026-09-05T10:00:01Z", "to": "2026-09-05T10:00:03Z"}, "sort": {"direction": "asc"}});
    assert_eq!(
        app.messages(&p, range).await,
        ["b 100% done_ok", "c error", "d warn"]
    );
    let (s, _) = app
        .admin(
            Method::POST,
            &format!("/v1/projects/{p}/logs/search"),
            Some(json!({"range": {"from": "2026-09-06T00:00:00Z", "to": "2026-09-05T00:00:00Z"}})),
        )
        .await;
    assert_eq!(s, StatusCode::UNPROCESSABLE_ENTITY);

    // Keyset pagination, both directions.
    for (dir, expected) in [
        (
            "desc",
            ["e fatal", "d warn", "c error", "b 100% done_ok", "a info"],
        ),
        (
            "asc",
            ["a info", "b 100% done_ok", "c error", "d warn", "e fatal"],
        ),
    ] {
        let mut seen = Vec::new();
        let mut cursor = Value::Null;
        for page in 0..3 {
            let r = app
                .search(
                    &p,
                    json!({"limit": 2, "cursor": cursor, "sort": {"direction": dir}}),
                )
                .await;
            seen.extend(
                r["records"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(|x| x["message"].as_str().unwrap().to_owned()),
            );
            assert_eq!(r["has_more"], page < 2);
            cursor = r["next_cursor"].clone();
            assert_eq!(cursor.is_null(), page == 2);
        }
        assert_eq!(seen, expected);
    }
    let (s, _) = app
        .admin(
            Method::POST,
            &format!("/v1/projects/{p}/logs/search"),
            Some(json!({"limit": 0})),
        )
        .await;
    assert_eq!(s, StatusCode::UNPROCESSABLE_ENTITY);
}

#[sqlx::test]
async fn count_facets_find_and_project_isolation(pool: PgPool) {
    let (app, p) = seeded(pool).await;
    let (other_project, _) = app.project().await;
    let path = format!("/v1/projects/{p}/logs");

    let (_, c) = app
        .admin(Method::POST, &format!("{path}/count"), Some(json!({})))
        .await;
    assert_eq!(c, json!({"count": 5}));
    let (_, c) = app
        .admin(
            Method::POST,
            &format!("{path}/count"),
            Some(json!({"max": 3})),
        )
        .await;
    assert_eq!(c, json!({"count": 3}), "saturates at max");
    let (_, c) = app
        .admin(Method::POST, &format!("{path}/count"), Some(json!({"filters": [{"field": "service.name", "operator": "equals", "value": "web"}]})))
        .await;
    assert_eq!(c, json!({"count": 2}));

    let (_, f) = app
        .admin(Method::GET, &format!("{path}/facets/service.name"), None)
        .await;
    assert_eq!(f, json!({"values": ["billing", "web"]}));
    let (_, sv) = app
        .admin(Method::GET, &format!("{path}/services"), None)
        .await;
    let names: Vec<_> = sv["services"]
        .as_array()
        .unwrap()
        .iter()
        .map(|x| x["name"].clone())
        .collect();
    assert_eq!(names, vec![json!("billing"), json!("web")]);
    let sum = |k: &str| {
        sv["services"]
            .as_array()
            .unwrap()
            .iter()
            .map(|x| x[k].as_i64().unwrap())
            .sum::<i64>()
    };
    assert_eq!(sum("total"), 5);
    assert!(sum("errors") <= 5);
    let (_, f) = app
        .admin(
            Method::GET,
            &format!("{path}/facets/environment?from=2026-09-05T10:00:00Z&to=2026-09-05T11:00:00Z"),
            None,
        )
        .await;
    assert_eq!(f, json!({"values": ["prod"]}));
    let (_, f) = app
        .admin(
            Method::GET,
            &format!("{path}/facets/environment?from=2030-01-01T00:00:00Z"),
            None,
        )
        .await;
    assert_eq!(f, json!({"values": []}));
    assert_eq!(
        app.admin(Method::GET, &format!("{path}/facets/message"), None)
            .await
            .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );

    let listed = app.search(&p, json!({"limit": 1})).await;
    let id = listed["records"][0]["id"].as_str().unwrap();
    let (s, one) = app.admin(Method::GET, &format!("{path}/{id}"), None).await;
    assert_eq!(s, StatusCode::OK);
    assert_eq!(one["exception"]["type"], "RuntimeException");
    assert_eq!(one["severity"], json!({"text": "ERROR", "number": 21}));
    // A log id never crosses a project boundary.
    let (s, _) = app
        .admin(
            Method::GET,
            &format!("/v1/projects/{other_project}/logs/{id}"),
            None,
        )
        .await;
    assert_eq!(s, StatusCode::NOT_FOUND);
    assert!(app.messages(&other_project, json!({})).await.is_empty());
}

#[sqlx::test]
async fn retention_purges_only_expired_logs(pool: PgPool) {
    let app = app(pool).await;
    let (_, key) = app.project().await;
    let old = (chrono::Utc::now() - chrono::Duration::days(30)).to_rfc3339();
    let fresh = chrono::Utc::now().to_rfc3339();
    let (s, _) = app
        .post_logs(Some(&key), json!({"records": [rec(&old, 9, "old", "api", json!({})), rec(&fresh, 9, "fresh", "api", json!({}))]}))
        .await;
    assert_eq!(s, StatusCode::ACCEPTED);

    assert_eq!(retention::purge_expired(&app.pool, 14).await.unwrap(), 1);
    let left: Vec<String> = sqlx::query_scalar("SELECT message FROM logs")
        .fetch_all(&app.pool)
        .await
        .unwrap();
    assert_eq!(left, ["fresh"]);
    assert_eq!(retention::purge_expired(&app.pool, 14).await.unwrap(), 0);
}

#[sqlx::test]
async fn histogram_buckets_include_empty_ones_and_apply_filters(pool: PgPool) {
    let (app, project) = seeded(pool).await;
    let path = format!("/v1/projects/{project}/logs/histogram");
    let hist = |body: Value| {
        let path = path.clone();
        let app = &app;
        async move { app.admin(Method::POST, &path, Some(body)).await }
    };

    // 10:00:00..04 -> sev 9, 9, 17, 13, 21; 2s buckets over [00, 05]: the 5 s range gives
    // 3 buckets, the row at :04 sits in the last one.
    let (st, v) = hist(json!({"range": {"from": "2026-09-05T10:00:00Z", "to": "2026-09-05T10:00:05Z"}, "interval": 2})).await;
    assert_eq!(st, StatusCode::OK, "{v}");
    assert_eq!(
        v["buckets"],
        json!([
            {"t": "2026-09-05T10:00:00Z", "total": 2, "errors": 0},
            {"t": "2026-09-05T10:00:02Z", "total": 2, "errors": 1},
            {"t": "2026-09-05T10:00:04Z", "total": 1, "errors": 1},
        ])
    );

    // Empty buckets are zeros; filters from logs/search apply.
    let (_, v) = hist(json!({
        "range": {"from": "2026-09-05T09:59:58Z", "to": "2026-09-05T10:00:04Z"},
        "interval": 2,
        "filters": [{"field": "service.name", "operator": "equals", "value": "web"}],
    }))
    .await;
    let totals: Vec<i64> = v["buckets"]
        .as_array()
        .unwrap()
        .iter()
        .map(|b| b["total"].as_i64().unwrap())
        .collect();
    assert_eq!(totals, [0, 1, 1]);

    // Limits and validation.
    for body in [
        json!({"range": {"from": "2026-09-05T10:00:00Z", "to": "2026-09-05T10:00:05Z"}, "interval": 0}),
        json!({"range": {"from": "2026-09-05T10:00:00Z", "to": "2026-09-05T10:00:05Z"}, "interval": 86401}),
        json!({"range": {"from": "2026-09-05T10:00:00Z"}, "interval": 60}),
        json!({"range": {"from": "2026-09-05T10:00:00Z", "to": "2026-09-05T10:00:05Z"}}),
        // 1001 one-second buckets
        json!({"range": {"from": "2026-09-05T10:00:00Z", "to": "2026-09-05T10:16:41Z"}, "interval": 1}),
        json!({"range": {"from": "2026-09-05T10:00:05Z", "to": "2026-09-05T10:00:00Z"}, "interval": 1}),
    ] {
        let (st, v) = hist(body.clone()).await;
        assert_eq!(st, StatusCode::UNPROCESSABLE_ENTITY, "{body} -> {v}");
    }
    // Exactly 1000 buckets is accepted.
    let (st, v) = hist(json!({"range": {"from": "2026-09-05T10:00:00Z", "to": "2026-09-05T10:16:40Z"}, "interval": 1})).await;
    assert_eq!(st, StatusCode::OK);
    assert_eq!(v["buckets"].as_array().unwrap().len(), 1000);
}

#[sqlx::test]
async fn all_projects_routes_aggregate_and_require_master_key(pool: PgPool) {
    let app = app(pool).await;
    let (p1, k1) = app.project().await;
    let (p2, k2) = app.project().await;
    for (key, msg, sev, svc) in [(&k1, "one", 9, "billing"), (&k2, "two", 17, "web")] {
        let (st, _) = app
            .post_logs(
                Some(key),
                json!({"records": [rec("2026-09-05T10:00:00Z", sev, msg, svc, json!({}))]}),
            )
            .await;
        assert_eq!(st, StatusCode::ACCEPTED);
    }

    let (_, v) = app
        .admin(Method::POST, "/v1/logs/search", Some(json!({})))
        .await;
    let recs = v["records"].as_array().unwrap();
    assert_eq!(recs.len(), 2);
    let mut pids: Vec<&str> = recs
        .iter()
        .map(|r| r["project_id"].as_str().unwrap())
        .collect();
    pids.sort();
    let mut expected = [p1.as_str(), p2.as_str()];
    expected.sort();
    assert_eq!(pids, expected);

    let (_, v) = app
        .admin(
            Method::POST,
            "/v1/logs/search",
            Some(json!({"search": "two"})),
        )
        .await;
    assert_eq!(v["records"][0]["project_id"], p2);

    let (_, v) = app
        .admin(Method::POST, "/v1/logs/count", Some(json!({})))
        .await;
    assert_eq!(v["count"], 2);

    let (_, v) = app
        .admin(Method::GET, "/v1/logs/facets/service.name", None)
        .await;
    assert_eq!(v["values"], json!(["billing", "web"]));

    let (_, v) = app
        .admin(
            Method::POST,
            "/v1/logs/histogram",
            Some(json!({"range": {"from": "2026-09-05T10:00:00Z", "to": "2026-09-05T10:01:00Z"}, "interval": 60})),
        )
        .await;
    assert_eq!(
        v["buckets"],
        json!([{"t": "2026-09-05T10:00:00Z", "total": 2, "errors": 1}])
    );

    // Auth: no token and a project key are both refused.
    for (method, path) in [
        (Method::POST, "/v1/logs/search"),
        (Method::POST, "/v1/logs/count"),
        (Method::POST, "/v1/logs/histogram"),
        (Method::GET, "/v1/logs/facets/service.name"),
        (Method::POST, "/v1/traces/search"),
        (
            Method::POST,
            "/v1/projects/00000000-0000-0000-0000-000000000000/logs/histogram",
        ),
    ] {
        for token in [None, Some(k1.as_str())] {
            let mut req = app
                .http
                .request(method.clone(), format!("{}{path}", app.base))
                .json(&json!({}));
            if let Some(t) = token {
                req = req.bearer_auth(t);
            }
            assert_eq!(
                req.send().await.unwrap().status(),
                StatusCode::UNAUTHORIZED,
                "{path}"
            );
        }
    }
}

#[sqlx::test]
async fn attribute_discovery_is_scoped_and_validates_key(pool: PgPool) {
    let (app, p) = seeded(pool).await;
    let (other, _) = app.project().await;
    let path = format!("/v1/projects/{p}/logs");

    let (s, k) = app
        .admin(Method::GET, &format!("{path}/attribute-keys"), None)
        .await;
    assert_eq!(s, StatusCode::OK);
    assert!(k["keys"].is_array());
    let (_, k) = app
        .admin(
            Method::GET,
            &format!("/v1/projects/{other}/logs/attribute-keys"),
            None,
        )
        .await;
    assert_eq!(
        k,
        json!({"keys": []}),
        "another project's attributes never leak"
    );

    let (s, _) = app
        .admin(
            Method::GET,
            &format!("{path}/attribute-values?key=a.b"),
            None,
        )
        .await;
    assert_eq!(s, StatusCode::OK);
    let (s, _) = app
        .admin(
            Method::GET,
            &format!("{path}/attribute-values?key=a-b"),
            None,
        )
        .await;
    assert_eq!(s, StatusCode::UNPROCESSABLE_ENTITY);
}
