//! OTLP trace ingestion + trace search, against a real PostgreSQL (see `tests/api.rs`).
#![allow(clippy::unwrap_used, clippy::expect_used)]

use std::io::Write;

use loghub_engine::{AppState, config::Limits, retention, router};
use opentelemetry_proto::tonic::{
    collector::trace::v1::{ExportTraceServiceRequest, ExportTraceServiceResponse},
    common::v1::{AnyValue, ArrayValue, KeyValue, any_value::Value as Av},
    resource::v1::Resource,
    trace::v1::{ResourceSpans, ScopeSpans, Span, Status, span::Event},
};
use prost::Message;
use reqwest::{Client, Method, StatusCode};
use serde_json::{Value, json};
use sqlx::PgPool;

const MASTER: &str = "test-master-key-0123456789";
const PB: &str = "application/x-protobuf";
/// 2026-09-05T10:00:00Z
const T0: u64 = 1_788_602_400_000_000_000;
const MS: u64 = 1_000_000;

struct App {
    base: String,
    pool: PgPool,
    http: Client,
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

async fn app(pool: PgPool) -> App {
    app_with(pool, Limits::default()).await
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

    async fn post_raw(
        &self,
        key: Option<&str>,
        content_type: &str,
        encoding: Option<&str>,
        body: Vec<u8>,
    ) -> reqwest::Response {
        let mut req = self
            .http
            .post(format!("{}/v1/traces", self.base))
            .header("content-type", content_type)
            .body(body);
        if let Some(k) = key {
            req = req.bearer_auth(k);
        }
        if let Some(e) = encoding {
            req = req.header("content-encoding", e);
        }
        req.send().await.unwrap()
    }

    /// Sends spans as protobuf; returns the decoded response.
    async fn export(
        &self,
        key: &str,
        service: &str,
        spans: Vec<Span>,
    ) -> ExportTraceServiceResponse {
        let res = self
            .post_raw(Some(key), PB, None, request(service, spans))
            .await;
        assert_eq!(res.status(), StatusCode::OK);
        assert_eq!(res.headers()["content-type"], PB);
        ExportTraceServiceResponse::decode(res.bytes().await.unwrap()).unwrap()
    }

    async fn search(&self, project: &str, body: Value) -> Value {
        let (s, v) = self
            .admin(
                Method::POST,
                &format!("/v1/projects/{project}/traces/search"),
                Some(body),
            )
            .await;
        assert_eq!(s, StatusCode::OK, "{v}");
        v
    }

    async fn trace_ids(&self, project: &str, body: Value) -> Vec<String> {
        self.search(project, body).await["traces"]
            .as_array()
            .unwrap()
            .iter()
            .map(|t| t["trace_id"].as_str().unwrap().to_owned())
            .collect()
    }
}

fn kv(key: &str, v: Av) -> KeyValue {
    KeyValue {
        key: key.into(),
        value: Some(AnyValue { value: Some(v) }),
    }
}

fn request(service: &str, spans: Vec<Span>) -> Vec<u8> {
    ExportTraceServiceRequest {
        resource_spans: vec![ResourceSpans {
            resource: Some(Resource {
                attributes: vec![
                    kv("service.name", Av::StringValue(service.into())),
                    kv(
                        "deployment.environment.name",
                        Av::StringValue("prod".into()),
                    ),
                ],
                ..Default::default()
            }),
            scope_spans: vec![ScopeSpans {
                spans,
                ..Default::default()
            }],
            ..Default::default()
        }],
    }
    .encode_to_vec()
}

fn tid(t: u8) -> String {
    hex::encode([t; 16])
}

/// `start`/`dur` in ms relative to T0. `parent == 0` means root.
fn span(t: u8, id: u8, parent: u8, name: &str, start: u64, dur: u64) -> Span {
    Span {
        trace_id: vec![t; 16],
        span_id: vec![id; 8],
        parent_span_id: if parent == 0 { vec![] } else { vec![parent; 8] },
        name: name.into(),
        kind: 2,
        start_time_unix_nano: T0 + start * MS,
        end_time_unix_nano: T0 + (start + dur) * MS,
        ..Default::default()
    }
}

fn with_attr(mut s: Span, k: &str, v: Av) -> Span {
    s.attributes.push(kv(k, v));
    s
}

fn failed(mut s: Span) -> Span {
    s.status = Some(Status {
        message: "boom".into(),
        code: 2,
    });
    s
}

#[sqlx::test]
async fn protobuf_ingest_stores_and_maps_spans(pool: PgPool) {
    let app = app(pool).await;
    let (project, key) = app.project().await;

    let mut child = with_attr(
        span(1, 2, 1, "SELECT", 10, 20),
        "db.system",
        Av::StringValue("pg".into()),
    );
    child.kind = 3;
    child.attributes.push(kv("n", Av::IntValue(7)));
    child.attributes.push(kv(
        "tags",
        Av::ArrayValue(ArrayValue {
            values: vec![AnyValue {
                value: Some(Av::BoolValue(true)),
            }],
        }),
    ));
    child
        .attributes
        .push(kv("raw", Av::BytesValue(vec![1, 2, 3])));
    child.events.push(Event {
        time_unix_nano: T0 + 15 * MS,
        name: "exception".into(),
        attributes: vec![kv("exception.message", Av::StringValue("kaput".into()))],
        ..Default::default()
    });
    let resp = app
        .export(
            &key,
            "api",
            vec![failed(span(1, 1, 0, "GET /pay", 0, 100)), child],
        )
        .await;
    assert!(resp.partial_success.is_none());

    let (s, t) = app
        .admin(
            Method::GET,
            &format!("/v1/projects/{project}/traces/{}", tid(1)),
            None,
        )
        .await;
    assert_eq!(s, StatusCode::OK);
    let spans = t["spans"].as_array().unwrap();
    assert_eq!(spans.len(), 2);
    let root = &spans[0];
    assert_eq!(root["name"], "GET /pay");
    assert_eq!(root["parent_span_id"], Value::Null);
    assert_eq!(root["span_id"], hex::encode([1u8; 8]));
    assert_eq!(root["kind"], "server");
    assert_eq!(root["status"], json!({"code": "error", "message": "boom"}));
    assert_eq!(root["duration_ns"], 100 * MS);
    assert_eq!(root["service_name"], "api");
    assert_eq!(root["environment"], "prod");
    assert_eq!(root["start_time"], "2026-09-05T10:00:00Z");
    let c = &spans[1];
    assert_eq!(c["parent_span_id"], hex::encode([1u8; 8]));
    assert_eq!(c["kind"], "client");
    assert_eq!(c["status"]["code"], "unset");
    assert_eq!(c["attributes"]["db.system"], "pg");
    assert_eq!(c["attributes"]["n"], 7);
    assert_eq!(c["attributes"]["tags"], json!([true]));
    assert_eq!(c["attributes"]["raw"], "AQID");
    assert_eq!(c["resource"]["service.name"], "api");
    assert_eq!(c["events"][0]["name"], "exception");
    assert_eq!(
        c["events"][0]["timestamp"],
        "2026-09-05T10:00:00.015000000Z"
    );
    assert_eq!(c["events"][0]["attributes"]["exception.message"], "kaput");
}

#[sqlx::test]
async fn gzip_is_supported_and_decompressed_size_is_bounded(pool: PgPool) {
    let app = app_with(
        pool,
        Limits {
            max_request_bytes: 2000,
            ..Limits::default()
        },
    )
    .await;
    let (project, key) = app.project().await;
    let gz = |data: &[u8]| {
        let mut e = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::default());
        e.write_all(data).unwrap();
        e.finish().unwrap()
    };

    let ok = request("api", vec![span(1, 1, 0, "root", 0, 5)]);
    let res = app.post_raw(Some(&key), PB, Some("gzip"), gz(&ok)).await;
    assert_eq!(res.status(), StatusCode::OK);
    assert_eq!(app.trace_ids(&project, json!({})).await, [tid(1)]);

    // Tiny on the wire, huge once inflated.
    let bomb = gz(&vec![0u8; 100_000]);
    assert!(bomb.len() < 2000);
    let res = app.post_raw(Some(&key), PB, Some("gzip"), bomb).await;
    assert_eq!(res.status(), StatusCode::PAYLOAD_TOO_LARGE);

    let res = app
        .post_raw(Some(&key), PB, Some("gzip"), b"not gzip".to_vec())
        .await;
    assert_eq!(res.status(), StatusCode::BAD_REQUEST);
    let res = app.post_raw(Some(&key), PB, Some("br"), ok.clone()).await;
    assert_eq!(res.status(), StatusCode::UNSUPPORTED_MEDIA_TYPE);
    let res = app.post_raw(Some(&key), PB, None, vec![0u8; 5000]).await;
    assert_eq!(res.status(), StatusCode::PAYLOAD_TOO_LARGE);
}

#[sqlx::test]
async fn invalid_requests_are_refused(pool: PgPool) {
    let app = app(pool).await;
    let (_, key) = app.project().await;
    let body = request("api", vec![span(1, 1, 0, "root", 0, 5)]);

    let res = app.post_raw(None, PB, None, body.clone()).await;
    assert_eq!(res.status(), StatusCode::UNAUTHORIZED);
    let res = app.post_raw(Some("lh_nope"), PB, None, body.clone()).await;
    assert_eq!(res.status(), StatusCode::UNAUTHORIZED);
    let res = app
        .post_raw(Some(&key), "application/json", None, b"{}".to_vec())
        .await;
    assert_eq!(res.status(), StatusCode::UNSUPPORTED_MEDIA_TYPE);
    let res = app
        .post_raw(Some(&key), PB, None, vec![0xff, 0xff, 0xff])
        .await;
    assert_eq!(res.status(), StatusCode::BAD_REQUEST);
    // Content-Type parameters are tolerated.
    let res = app
        .post_raw(
            Some(&key),
            "application/x-protobuf; charset=utf-8",
            None,
            body,
        )
        .await;
    assert_eq!(res.status(), StatusCode::OK);
}

#[sqlx::test]
async fn invalid_spans_are_counted_and_valid_ones_stored(pool: PgPool) {
    let app = app(pool).await;
    let (project, key) = app.project().await;
    let mut bad_trace = span(2, 1, 0, "x", 0, 1);
    bad_trace.trace_id = vec![0; 16];
    let mut bad_span = span(2, 1, 0, "x", 0, 1);
    bad_span.span_id = vec![1; 3];
    let mut backwards = span(2, 2, 0, "x", 0, 1);
    backwards.end_time_unix_nano = T0 - 1;
    let unnamed = span(2, 3, 0, "", 0, 1);
    let nul = span(2, 4, 0, "a\0b", 0, 1);

    let resp = app
        .export(
            &key,
            "api",
            vec![
                bad_trace,
                bad_span,
                backwards,
                unnamed,
                nul,
                span(1, 1, 0, "good", 0, 1),
            ],
        )
        .await;
    let ps = resp.partial_success.unwrap();
    assert_eq!(ps.rejected_spans, 5);
    assert!(!ps.error_message.is_empty());
    assert_eq!(app.trace_ids(&project, json!({})).await, [tid(1)]);
}

#[sqlx::test]
async fn retried_export_is_idempotent(pool: PgPool) {
    let app = app(pool).await;
    let (project, key) = app.project().await;
    let spans = || vec![span(1, 1, 0, "root", 0, 5), span(1, 2, 1, "child", 1, 2)];
    app.export(&key, "api", spans()).await;
    app.export(&key, "api", spans()).await;
    let r = app.search(&project, json!({})).await;
    assert_eq!(r["traces"][0]["span_count"], 2);
}

#[sqlx::test]
async fn search_matches_traces_by_any_span_and_summarises_the_whole_trace(pool: PgPool) {
    let app = app(pool).await;
    let (project, key) = app.project().await;
    // Trace 1: api root -> db child (error, attribute), 0..100ms.
    app.export(
        &key,
        "api",
        vec![
            span(1, 1, 0, "GET /pay", 0, 100),
            with_attr(
                failed(span(1, 2, 1, "SELECT", 10, 50)),
                "db.system",
                Av::StringValue("pg".into()),
            ),
        ],
    )
    .await;
    // Same trace, other service.
    app.export(&key, "billing", vec![span(1, 3, 2, "charge", 20, 120)])
        .await;
    // Trace 2: later, fast, no error, orphan child only (root never arrived).
    app.export(&key, "web", vec![span(2, 9, 8, "orphan", 1000, 5)])
        .await;

    let all = app.search(&project, json!({})).await;
    let traces = all["traces"].as_array().unwrap();
    assert_eq!(traces.len(), 2);
    assert_eq!(traces[0]["trace_id"], tid(2), "most recent trace first");
    assert_eq!(traces[0]["root"], Value::Null);
    let t1 = &traces[1];
    assert_eq!(
        t1["root"],
        json!({"span_id": hex::encode([1u8; 8]), "name": "GET /pay", "service_name": "api"})
    );
    assert_eq!(t1["span_count"], 3);
    assert_eq!(t1["error_count"], 1);
    assert_eq!(t1["duration_ns"], 140 * MS);
    assert_eq!(t1["services"], json!(["api", "billing"]));
    assert_eq!(t1["start_time"], "2026-09-05T10:00:00Z");

    let ids = |f: Value| app.trace_ids(&project, json!({"filters": [f]}));
    let f = |field: &str, op: &str, v: Value| json!({"field": field, "operator": op, "value": v});
    // A filter on a child span returns the trace, summarised in full.
    assert_eq!(
        ids(f("attributes.db.system", "equals", json!("pg"))).await,
        [tid(1)]
    );
    assert_eq!(
        ids(f("service.name", "equals", json!("billing"))).await,
        [tid(1)]
    );
    assert_eq!(ids(f("status", "equals", json!("error"))).await, [tid(1)]);
    assert_eq!(
        ids(f("kind", "equals", json!("server"))).await,
        [tid(2), tid(1)]
    );
    assert_eq!(ids(f("name", "contains", json!("select"))).await, [tid(1)]);
    assert_eq!(
        ids(f("duration", "greater_than", json!(100 * MS))).await,
        [tid(1)]
    );
    assert_eq!(
        ids(f(
            "resource.deployment.environment.name",
            "equals",
            json!("prod")
        ))
        .await
        .len(),
        2
    );
    assert_eq!(ids(f("trace.id", "equals", json!(tid(2)))).await, [tid(2)]);
    assert!(
        ids(f("attributes.db.system", "equals", json!("mysql")))
            .await
            .is_empty()
    );
    // Filters are ANDed per span: no single span is both billing and in error.
    let both = json!({"filters": [
        f("service.name", "equals", json!("billing")), f("status", "equals", json!("error"))]});
    assert!(app.trace_ids(&project, both).await.is_empty());

    // Range applies to span start; the trace is still summarised from all its spans.
    let r = app
        .search(&project, json!({"range": {"from": "2026-09-05T10:00:00.015Z", "to": "2026-09-05T10:00:00.025Z"}}))
        .await;
    assert_eq!(r["traces"].as_array().unwrap().len(), 1);
    assert_eq!(r["traces"][0]["span_count"], 3);
    assert_eq!(r["traces"][0]["start_time"], "2026-09-05T10:00:00Z");

    for bad in [
        json!({"filters": [f("nope", "equals", json!("x"))]}),
        json!({"filters": [f("duration", "equals", json!("abc"))]}),
        json!({"filters": [f("attributes.bad key", "exists", Value::Null)]}),
        json!({"limit": 0}),
        json!({"limit": 201}),
        json!({"range": {"from": "2026-09-06T00:00:00Z", "to": "2026-09-05T00:00:00Z"}}),
    ] {
        let (s, _) = app
            .admin(
                Method::POST,
                &format!("/v1/projects/{project}/traces/search"),
                Some(bad.clone()),
            )
            .await;
        assert_eq!(s, StatusCode::UNPROCESSABLE_ENTITY, "{bad}");
    }
}

#[sqlx::test]
async fn search_pages_with_a_keyset_cursor(pool: PgPool) {
    let app = app(pool).await;
    let (project, key) = app.project().await;
    // Traces 1..=5, two of them with identical start times.
    let spans = (1u8..=5)
        .map(|t| span(t, 1, 0, "root", u64::from(t.min(4)) * 10, 1))
        .collect();
    app.export(&key, "api", spans).await;

    let mut seen = Vec::new();
    let mut cursor = Value::Null;
    loop {
        let r = app
            .search(&project, json!({"limit": 2, "cursor": cursor}))
            .await;
        seen.extend(
            r["traces"]
                .as_array()
                .unwrap()
                .iter()
                .map(|t| t["trace_id"].as_str().unwrap().to_owned()),
        );
        if !r["has_more"].as_bool().unwrap() {
            assert_eq!(r["next_cursor"], Value::Null);
            break;
        }
        cursor = r["next_cursor"].clone();
        assert_ne!(cursor, Value::Null);
    }
    assert_eq!(seen, [tid(5), tid(4), tid(3), tid(2), tid(1)]);
}

#[sqlx::test]
async fn trace_detail_and_project_isolation(pool: PgPool) {
    let app = app(pool).await;
    let (p1, k1) = app.project().await;
    let (p2, k2) = app.project().await;
    app.export(&k1, "api", vec![span(1, 1, 0, "mine", 0, 1)])
        .await;
    app.export(&k2, "api", vec![span(1, 1, 0, "theirs", 0, 1)])
        .await;

    let get = |p: &str, t: &str| {
        app.admin(
            Method::GET,
            format!("/v1/projects/{p}/traces/{t}").leak(),
            None,
        )
    };
    let (s, v) = get(&p1, &tid(1)).await;
    assert_eq!(
        (s, v["spans"][0]["name"].clone()),
        (StatusCode::OK, json!("mine"))
    );
    let (_, v) = get(&p2, &tid(1)).await;
    assert_eq!(v["spans"][0]["name"], "theirs");
    assert_eq!(get(&p1, &tid(9)).await.0, StatusCode::NOT_FOUND);
    assert_eq!(get(&p1, "not-a-trace").await.0, StatusCode::NOT_FOUND);

    let r1 = app.search(&p1, json!({})).await;
    assert_eq!(r1["traces"].as_array().unwrap().len(), 1);
    assert_eq!(r1["traces"][0]["root"]["name"], "mine");
    // The query API needs the master key; a project key is not one.
    let res = app
        .http
        .get(format!("{}/v1/projects/{p1}/traces/{}", app.base, tid(1)))
        .send()
        .await
        .unwrap();
    assert_eq!(res.status(), StatusCode::UNAUTHORIZED);
    let res = app
        .http
        .get(format!("{}/v1/projects/{p1}/traces/{}", app.base, tid(1)))
        .bearer_auth(&k1)
        .send()
        .await
        .unwrap();
    assert_eq!(res.status(), StatusCode::UNAUTHORIZED);
}

#[sqlx::test]
async fn retention_purges_expired_spans(pool: PgPool) {
    let app = app(pool).await;
    let (project, key) = app.project().await;
    let now = u64::try_from(chrono::Utc::now().timestamp_nanos_opt().unwrap()).unwrap();
    let mut old = span(1, 1, 0, "old", 0, 1);
    old.start_time_unix_nano = now - 30 * 86_400 * 1_000_000_000;
    old.end_time_unix_nano = old.start_time_unix_nano + MS;
    let mut fresh = span(2, 1, 0, "fresh", 0, 1);
    fresh.start_time_unix_nano = now;
    fresh.end_time_unix_nano = now + MS;
    app.export(&key, "api", vec![old, fresh]).await;

    assert_eq!(retention::purge_expired(&app.pool, 14).await.unwrap(), 1);
    assert_eq!(app.trace_ids(&project, json!({})).await, [tid(2)]);
    assert_eq!(retention::purge_expired(&app.pool, 14).await.unwrap(), 0);
}
