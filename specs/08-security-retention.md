# 8. Security, Redaction, Retention

## 8.1 User authentication

V1 uses standard Symfony authentication.

All authenticated users can access all projects in the initial release.

Authorization must still be structured so that project-level permissions can be introduced later.

## 8.2 API keys

- one API key per project
- key identifies the project
- keys can be revoked
- keys can be rotated
- store only a secure hash where feasible
- reveal full key only at creation/rotation time

## 8.3 Redaction

Redaction happens client-side before transmission.

Default recursive sensitive-key patterns should include at least:

- password
- passwd
- secret
- token
- access_token
- refresh_token
- authorization
- cookie
- set-cookie
- api_key
- apikey

Replacement value:

```text
[REDACTED]
```

Allow project applications to add custom sensitive field names.

## 8.4 Retention

Retention is configurable per project with a global default.

V1 cleanup can use scheduled deletion by timestamp.

Future optimization may use date partitioning and partition drops.

## 8.5 Failure isolation

The logging client is fail-open.

If LogHub is unavailable:

- do not throw into business code
- drop the remote batch in V1
- preserve other Monolog handlers
- expose local diagnostics without recursively logging to LogHub
