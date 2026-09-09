# Symfony Monolog Bundle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `packages/monolog-bundle`, a standalone Symfony bundle that maps Monolog log records to LogHub's canonical JSON shape, enriches/redacts them, buffers them in memory, and ships them to `POST /api/v1/logs` without ever failing the host application.

**Architecture:** A linear pipeline (`LogHubHandler` → `MonologRecordMapper` → enrichers → `AttributeRedactor` → `LogBuffer` → `HttpBatchTransport`) built from small, independently-testable, immutable value objects and single-responsibility services, wired by a standard Symfony bundle `Configuration`/`Extension` pair. Every stage after the handler's own try/catch is exception-free by construction; the handler and the transport are the two places fail-open is enforced.

**Tech Stack:** PHP 8.4, Symfony 8.1 components (`config`, `dependency-injection`, `http-kernel`, `http-client`, `http-foundation`, `console`, `security-core`, `uid`), `monolog/monolog` ^3.7, PHPUnit 13.3.

**Spec:** `docs/superpowers/specs/2026-09-09-monolog-bundle-design.md` (implements `specs/03-symfony-bundle.md`, wire format from `specs/04-ingestion-api.md`, redaction rules from `specs/08-security-retention.md`)

## Global Constraints

- PHP `>=8.4`, Symfony components pinned `8.1.*` — matches root `composer.json`.
- The bundle has zero dependency on `App\*`; it ships its own value objects under `LogHub\MonologBundle\Model`.
- No `symfony/serializer` — every wire model implements `JsonSerializable` by hand so the exact dotted-key JSON shape from spec 04.3 is produced without fighting normalizer conventions.
- Fail-open (spec 3.7 / 8.5): nothing in this bundle may throw into the host application. The only two places exceptions are caught are `LogHubHandler::write()` (pipeline stages) and `HttpBatchTransport::send()` (network I/O). No disk spool, no retry — a failed batch is dropped.
- Deviation from the design doc, called out here for the record: the design listed a `TraceContext` model object. The wire payload (spec 04.3) has flat top-level `trace_id`/`span_id` fields, and V1 has no trace source to populate them from, so `LogRecord` carries `?string $traceId`/`?string $spanId` directly and no separate `TraceContext` class is created — avoids dead code (YAGNI).
- Package lives at `packages/monolog-bundle/` with its own `composer.json`, its own `vendor/`, and its own PHPUnit suite — run tests with `cd packages/monolog-bundle && vendor/bin/phpunit`, not the root suite.
- Namespace: `LogHub\MonologBundle` (src), `LogHub\MonologBundle\Tests` (tests).

---

## Task 1: Package scaffold + `Severity` model

**Files:**
- Create: `packages/monolog-bundle/composer.json`
- Create: `packages/monolog-bundle/phpunit.xml.dist`
- Create: `packages/monolog-bundle/src/Model/Severity.php`
- Test: `packages/monolog-bundle/tests/Model/SeverityTest.php`

**Interfaces:**
- Produces: `LogHub\MonologBundle\Model\Severity { __construct(string $text, int $number); jsonSerialize(): array }`

- [ ] **Step 1: Create the package composer.json**

```json
{
    "name": "loghub/monolog-bundle",
    "type": "symfony-bundle",
    "description": "Symfony Monolog integration bundle for LogHub log ingestion (spec 03).",
    "license": "proprietary",
    "require": {
        "php": ">=8.4",
        "monolog/monolog": "^3.7",
        "symfony/config": "8.1.*",
        "symfony/console": "8.1.*",
        "symfony/dependency-injection": "8.1.*",
        "symfony/event-dispatcher": "8.1.*",
        "symfony/http-client": "8.1.*",
        "symfony/http-foundation": "8.1.*",
        "symfony/http-kernel": "8.1.*",
        "symfony/security-core": "8.1.*",
        "symfony/uid": "8.1.*"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.3"
    },
    "autoload": {
        "psr-4": {
            "LogHub\\MonologBundle\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "LogHub\\MonologBundle\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 2: Create the package PHPUnit config**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         colors="true"
         cacheDirectory=".phpunit.cache"
>
    <testsuites>
        <testsuite name="LogHub Monolog Bundle Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Install the package's own dependencies**

Run: `cd packages/monolog-bundle && composer install`
Expected: succeeds, creates `packages/monolog-bundle/vendor/bin/phpunit`.

- [ ] **Step 4: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Model;

use LogHub\MonologBundle\Model\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testJsonSerializeProducesTextAndNumber(): void
    {
        $severity = new Severity('ERROR', 17);

        self::assertSame(['text' => 'ERROR', 'number' => 17], $severity->jsonSerialize());
    }
}
```

- [ ] **Step 5: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Model/SeverityTest.php`
Expected: FAIL — `Class "LogHub\MonologBundle\Model\Severity" not found`.

- [ ] **Step 6: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Model;

final readonly class Severity implements \JsonSerializable
{
    public function __construct(
        public string $text,
        public int $number,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text,
            'number' => $this->number,
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Model/SeverityTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add packages/monolog-bundle/composer.json packages/monolog-bundle/composer.lock packages/monolog-bundle/phpunit.xml.dist packages/monolog-bundle/src/Model/Severity.php packages/monolog-bundle/tests/Model/SeverityTest.php packages/monolog-bundle/.gitignore
git commit -m "feat(monolog-bundle): scaffold package and add Severity model"
```

Before this commit, create `packages/monolog-bundle/.gitignore` containing `/vendor/\n.phpunit.cache/\n` so the package's own vendor tree isn't committed.

---

## Task 2: Remaining wire models

**Files:**
- Create: `packages/monolog-bundle/src/Model/ResourceAttributes.php`
- Create: `packages/monolog-bundle/src/Model/LogAttributes.php`
- Create: `packages/monolog-bundle/src/Model/ExceptionData.php`
- Create: `packages/monolog-bundle/src/Model/LogRecord.php`
- Test: `packages/monolog-bundle/tests/Model/ResourceAttributesTest.php`
- Test: `packages/monolog-bundle/tests/Model/LogAttributesTest.php`
- Test: `packages/monolog-bundle/tests/Model/ExceptionDataTest.php`
- Test: `packages/monolog-bundle/tests/Model/LogRecordTest.php`

**Interfaces:**
- Consumes: `Severity` (Task 1)
- Produces:
  - `ResourceAttributes { __construct(array $attributes = []); with(string $key, string|int|float|bool|null $value): self; toArray(): array; jsonSerialize(): array }`
  - `LogAttributes { __construct(array $attributes = []); with(string $key, mixed $value): self; toArray(): array; jsonSerialize(): array }`
  - `ExceptionData { __construct(string $type, string $message, string $stacktrace, int|string|null $code = null); jsonSerialize(): array }`
  - `LogRecord { __construct(\DateTimeImmutable $timestamp, Severity $severity, string $message, ?string $channel, ResourceAttributes $resource, LogAttributes $attributes, ?string $traceId = null, ?string $spanId = null, ?ExceptionData $exception = null); withResource(ResourceAttributes): self; withAttributes(LogAttributes): self; jsonSerialize(): array }`

- [ ] **Step 1: Write failing tests for `ResourceAttributes` and `LogAttributes`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Model;

use LogHub\MonologBundle\Model\ResourceAttributes;
use PHPUnit\Framework\TestCase;

final class ResourceAttributesTest extends TestCase
{
    public function testWithAddsKeyImmutably(): void
    {
        $original = new ResourceAttributes(['service.name' => 'billing-api']);
        $updated = $original->with('host.name', 'api-02');

        self::assertSame(['service.name' => 'billing-api'], $original->toArray());
        self::assertSame(['service.name' => 'billing-api', 'host.name' => 'api-02'], $updated->toArray());
        self::assertSame($updated->toArray(), $updated->jsonSerialize());
    }
}
```

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Model;

use LogHub\MonologBundle\Model\LogAttributes;
use PHPUnit\Framework\TestCase;

final class LogAttributesTest extends TestCase
{
    public function testWithAddsKeyImmutably(): void
    {
        $original = new LogAttributes(['payment_id' => 123]);
        $updated = $original->with('provider', 'stripe');

        self::assertSame(['payment_id' => 123], $original->toArray());
        self::assertSame(['payment_id' => 123, 'provider' => 'stripe'], $updated->toArray());
        self::assertSame($updated->toArray(), $updated->jsonSerialize());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Model/ResourceAttributesTest.php tests/Model/LogAttributesTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `ResourceAttributes` and `LogAttributes`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Model;

final readonly class ResourceAttributes implements \JsonSerializable
{
    /** @param array<string, string|int|float|bool|null> $attributes dotted OTel-style keys, e.g. "service.name" */
    public function __construct(
        private array $attributes = [],
    ) {
    }

    public function with(string $key, string|int|float|bool|null $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self($attributes);
    }

    /** @return array<string, string|int|float|bool|null> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Model;

final readonly class LogAttributes implements \JsonSerializable
{
    /** @param array<string, mixed> $attributes JSON-compatible values only (spec 3.4) */
    public function __construct(
        private array $attributes = [],
    ) {
    }

    public function with(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self($attributes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Model/ResourceAttributesTest.php tests/Model/LogAttributesTest.php`
Expected: PASS

- [ ] **Step 5: Write failing test for `ExceptionData`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Model;

use LogHub\MonologBundle\Model\ExceptionData;
use PHPUnit\Framework\TestCase;

final class ExceptionDataTest extends TestCase
{
    public function testJsonSerializeProducesSpecShape(): void
    {
        $exception = new ExceptionData('RuntimeException', 'Provider unavailable', '#0 ...', 0);

        self::assertSame([
            'type' => 'RuntimeException',
            'message' => 'Provider unavailable',
            'stacktrace' => '#0 ...',
            'code' => 0,
        ], $exception->jsonSerialize());
    }
}
```

- [ ] **Step 6: Run test to verify it fails, then implement**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Model/ExceptionDataTest.php` — expect FAIL, class not found.

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Model;

final readonly class ExceptionData implements \JsonSerializable
{
    public function __construct(
        public string $type,
        public string $message,
        public string $stacktrace,
        public int|string|null $code = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'stacktrace' => $this->stacktrace,
            'code' => $this->code,
        ];
    }
}
```

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Model/ExceptionDataTest.php` — expect PASS.

- [ ] **Step 7: Write failing test for `LogRecord` matching spec 04.3 shape**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Model;

use LogHub\MonologBundle\Model\ExceptionData;
use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use PHPUnit\Framework\TestCase;

final class LogRecordTest extends TestCase
{
    public function testJsonSerializeMatchesSpec0403Shape(): void
    {
        $record = new LogRecord(
            timestamp: new \DateTimeImmutable('2026-09-09T14:45:01.231+00:00'),
            severity: new Severity('ERROR', 17),
            message: 'Payment failed',
            channel: 'payment',
            resource: new ResourceAttributes(['service.name' => 'billing-api']),
            attributes: new LogAttributes(['payment_id' => 123, 'provider' => 'stripe']),
            exception: new ExceptionData('RuntimeException', 'Provider unavailable', '#0 ...'),
        );

        $json = json_decode(json_encode($record, JSON_THROW_ON_ERROR), true);

        self::assertSame('2026-09-09T14:45:01.231Z', $json['timestamp']);
        self::assertSame(['text' => 'ERROR', 'number' => 17], $json['severity']);
        self::assertSame('Payment failed', $json['message']);
        self::assertSame('payment', $json['channel']);
        self::assertSame(['service.name' => 'billing-api'], $json['resource']);
        self::assertSame(['payment_id' => 123, 'provider' => 'stripe'], $json['attributes']);
        self::assertNull($json['trace_id']);
        self::assertNull($json['span_id']);
        self::assertSame('RuntimeException', $json['exception']['type']);
    }

    public function testWithHelpersReturnNewInstances(): void
    {
        $record = new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: 'hello',
            channel: null,
            resource: new ResourceAttributes(),
            attributes: new LogAttributes(),
        );

        $withResource = $record->withResource(new ResourceAttributes(['host.name' => 'api-02']));
        $withAttributes = $record->withAttributes(new LogAttributes(['k' => 'v']));

        self::assertSame([], $record->resource->toArray());
        self::assertSame(['host.name' => 'api-02'], $withResource->resource->toArray());
        self::assertSame([], $record->attributes->toArray());
        self::assertSame(['k' => 'v'], $withAttributes->attributes->toArray());
    }
}
```

- [ ] **Step 8: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Model/LogRecordTest.php`
Expected: FAIL — class not found.

- [ ] **Step 9: Implement `LogRecord`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Model;

final readonly class LogRecord implements \JsonSerializable
{
    public function __construct(
        public \DateTimeImmutable $timestamp,
        public Severity $severity,
        public string $message,
        public ?string $channel,
        public ResourceAttributes $resource,
        public LogAttributes $attributes,
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?ExceptionData $exception = null,
    ) {
    }

    public function withResource(ResourceAttributes $resource): self
    {
        return new self(
            $this->timestamp,
            $this->severity,
            $this->message,
            $this->channel,
            $resource,
            $this->attributes,
            $this->traceId,
            $this->spanId,
            $this->exception,
        );
    }

    public function withAttributes(LogAttributes $attributes): self
    {
        return new self(
            $this->timestamp,
            $this->severity,
            $this->message,
            $this->channel,
            $this->resource,
            $attributes,
            $this->traceId,
            $this->spanId,
            $this->exception,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.v\Z'),
            'severity' => $this->severity,
            'message' => $this->message,
            'channel' => $this->channel,
            'resource' => $this->resource,
            'attributes' => $this->attributes,
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'exception' => $this->exception,
        ];
    }
}
```

- [ ] **Step 10: Run all Model tests to verify they pass**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Model`
Expected: PASS (all)

- [ ] **Step 11: Commit**

```bash
git add packages/monolog-bundle/src/Model packages/monolog-bundle/tests/Model
git commit -m "feat(monolog-bundle): add ResourceAttributes, LogAttributes, ExceptionData, LogRecord models"
```

---

## Task 3: `MonologRecordMapper`

**Files:**
- Create: `packages/monolog-bundle/src/Mapper/MonologRecordMapper.php`
- Test: `packages/monolog-bundle/tests/Mapper/MonologRecordMapperTest.php`

**Interfaces:**
- Consumes: `Model\LogRecord`, `Model\Severity`, `Model\LogAttributes`, `Model\ResourceAttributes`, `Model\ExceptionData` (Tasks 1-2); `Monolog\LogRecord`, `Monolog\Level` (monolog/monolog)
- Produces: `MonologRecordMapper { map(\Monolog\LogRecord $record): LogHub\MonologBundle\Model\LogRecord }`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Mapper;

use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use Monolog\Level;
use Monolog\LogRecord as MonologLogRecord;
use PHPUnit\Framework\TestCase;

final class MonologRecordMapperTest extends TestCase
{
    private MonologRecordMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MonologRecordMapper();
    }

    private function monologRecord(array $context = [], array $extra = []): MonologLogRecord
    {
        return new MonologLogRecord(
            datetime: new \DateTimeImmutable('2026-09-09T14:45:01.231+00:00'),
            channel: 'payment',
            level: Level::Error,
            message: 'Payment failed',
            context: $context,
            extra: $extra,
        );
    }

    public function testMapsCoreFields(): void
    {
        $record = $this->mapper->map($this->monologRecord());

        self::assertSame('Payment failed', $record->message);
        self::assertSame('payment', $record->channel);
        self::assertSame('ERROR', $record->severity->text);
        self::assertSame(17, $record->severity->number);
        self::assertSame('2026-09-09T14:45:01.231Z', $record->timestamp->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function testContextAndExtraBecomeAttributes(): void
    {
        $record = $this->mapper->map($this->monologRecord(
            context: ['payment_id' => 123, 'provider' => 'stripe'],
            extra: ['request.id' => 'abc'],
        ));

        self::assertSame(
            ['payment_id' => 123, 'provider' => 'stripe', 'request.id' => 'abc'],
            $record->attributes->toArray(),
        );
    }

    public function testNonJsonCompatibleContextValuesAreDropped(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $record = $this->mapper->map($this->monologRecord(context: [
                'kept' => 'value',
                'dropped_resource' => $resource,
                'dropped_object' => new \stdClass(),
            ]));

            self::assertSame(['kept' => 'value'], $record->attributes->toArray());
        } finally {
            fclose($resource);
        }
    }

    public function testNestedArraysAreRecursivelyFiltered(): void
    {
        $record = $this->mapper->map($this->monologRecord(context: [
            'nested' => ['a' => 1, 'b' => new \stdClass()],
        ]));

        self::assertSame(['nested' => ['a' => 1]], $record->attributes->toArray());
    }

    public function testExceptionContextIsExtractedAndRemovedFromAttributes(): void
    {
        $exception = new \RuntimeException('Provider unavailable', 7);

        $record = $this->mapper->map($this->monologRecord(context: [
            'payment_id' => 123,
            'exception' => $exception,
        ]));

        self::assertNotNull($record->exception);
        self::assertSame(\RuntimeException::class, $record->exception->type);
        self::assertSame('Provider unavailable', $record->exception->message);
        self::assertSame(7, $record->exception->code);
        self::assertArrayNotHasKey('exception', $record->attributes->toArray());
        self::assertSame(['payment_id' => 123], $record->attributes->toArray());
    }

    public function testNoExceptionInContextLeavesExceptionNull(): void
    {
        $record = $this->mapper->map($this->monologRecord());

        self::assertNull($record->exception);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Mapper/MonologRecordMapperTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `MonologRecordMapper`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Mapper;

use LogHub\MonologBundle\Model\ExceptionData;
use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use Monolog\Level;
use Monolog\LogRecord as MonologLogRecord;

final class MonologRecordMapper
{
    /** @var array<int, int> Monolog\Level::value => OTel-style severity number (spec 3, example uses ERROR=17) */
    private const array LEVEL_TO_SEVERITY_NUMBER = [
        Level::Debug->value => 5,
        Level::Info->value => 9,
        Level::Notice->value => 10,
        Level::Warning->value => 13,
        Level::Error->value => 17,
        Level::Critical->value => 18,
        Level::Alert->value => 19,
        Level::Emergency->value => 21,
    ];

    public function map(MonologLogRecord $record): LogRecord
    {
        $context = $record->context;
        $exception = $this->extractException($context);
        unset($context['exception']);

        $attributes = new LogAttributes([
            ...$this->filterJsonCompatible($context),
            ...$this->filterJsonCompatible($record->extra),
        ]);

        return new LogRecord(
            timestamp: \DateTimeImmutable::createFromInterface($record->datetime)->setTimezone(new \DateTimeZone('UTC')),
            severity: new Severity($record->level->getName(), self::LEVEL_TO_SEVERITY_NUMBER[$record->level->value] ?? 0),
            message: $record->message,
            channel: $record->channel,
            resource: new ResourceAttributes(),
            attributes: $attributes,
            exception: $exception,
        );
    }

    /** @param array<string, mixed> $context */
    private function extractException(array $context): ?ExceptionData
    {
        $exception = $context['exception'] ?? null;

        if (!$exception instanceof \Throwable) {
            return null;
        }

        return new ExceptionData(
            type: $exception::class,
            message: $exception->getMessage(),
            stacktrace: $exception->getTraceAsString(),
            code: $exception->getCode(),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function filterJsonCompatible(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $result[$key] = $this->filterJsonCompatible($value);
            } elseif (null === $value || \is_scalar($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Mapper/MonologRecordMapperTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/monolog-bundle/src/Mapper packages/monolog-bundle/tests/Mapper
git commit -m "feat(monolog-bundle): add MonologRecordMapper"
```

---

## Task 4: `AttributeRedactor`

**Files:**
- Modify: `packages/monolog-bundle/src/Model/ResourceAttributes.php` (already has `toArray()` from Task 2 — no change needed, listed for context)
- Create: `packages/monolog-bundle/src/Redaction/AttributeRedactor.php`
- Test: `packages/monolog-bundle/tests/Redaction/AttributeRedactorTest.php`

**Interfaces:**
- Consumes: `Model\LogRecord`, `Model\LogAttributes`, `Model\ResourceAttributes` (Task 2)
- Produces: `AttributeRedactor { __construct(array $extraSensitiveKeys = []); redact(LogRecord $record): LogRecord }`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Redaction;

use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use PHPUnit\Framework\TestCase;

final class AttributeRedactorTest extends TestCase
{
    private function record(array $attributes, array $resource = []): LogRecord
    {
        return new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: 'msg',
            channel: null,
            resource: new ResourceAttributes($resource),
            attributes: new LogAttributes($attributes),
        );
    }

    /** @dataProvider defaultSensitiveKeys */
    public function testDefaultSensitiveKeysAreRedacted(string $key): void
    {
        $redactor = new AttributeRedactor();
        $redacted = $redactor->redact($this->record([$key => 'secret-value', 'safe' => 'ok']));

        self::assertSame('[REDACTED]', $redacted->attributes->toArray()[$key]);
        self::assertSame('ok', $redacted->attributes->toArray()['safe']);
    }

    public static function defaultSensitiveKeys(): array
    {
        return array_map(
            static fn (string $key) => [$key],
            ['password', 'passwd', 'secret', 'token', 'access_token', 'refresh_token', 'authorization', 'cookie', 'set-cookie', 'api_key', 'apikey'],
        );
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $redactor = new AttributeRedactor();
        $redacted = $redactor->redact($this->record(['Authorization' => 'Bearer xyz']));

        self::assertSame('[REDACTED]', $redacted->attributes->toArray()['Authorization']);
    }

    public function testRedactsAtAnyNestingDepth(): void
    {
        $redactor = new AttributeRedactor();
        $redacted = $redactor->redact($this->record([
            'headers' => ['authorization' => 'Bearer xyz', 'accept' => 'json'],
        ]));

        self::assertSame(
            ['authorization' => '[REDACTED]', 'accept' => 'json'],
            $redacted->attributes->toArray()['headers'],
        );
    }

    public function testExtraSensitiveKeysAreConfigurable(): void
    {
        $redactor = new AttributeRedactor(['x-internal-key']);
        $redacted = $redactor->redact($this->record(['x-internal-key' => 'value']));

        self::assertSame('[REDACTED]', $redacted->attributes->toArray()['x-internal-key']);
    }

    public function testResourceAttributesAreAlsoRedacted(): void
    {
        $redactor = new AttributeRedactor();
        $redacted = $redactor->redact($this->record([], ['token' => 'abc', 'service.name' => 'billing-api']));

        self::assertSame('[REDACTED]', $redacted->resource->toArray()['token']);
        self::assertSame('billing-api', $redacted->resource->toArray()['service.name']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Redaction/AttributeRedactorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `AttributeRedactor`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Redaction;

use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;

final class AttributeRedactor
{
    private const array DEFAULT_SENSITIVE_KEYS = [
        'password', 'passwd', 'secret', 'token', 'access_token',
        'refresh_token', 'authorization', 'cookie', 'set-cookie',
        'api_key', 'apikey',
    ];

    private const string REPLACEMENT = '[REDACTED]';

    /** @var list<string> */
    private readonly array $sensitiveKeys;

    /** @param list<string> $extraSensitiveKeys */
    public function __construct(array $extraSensitiveKeys = [])
    {
        $this->sensitiveKeys = array_map(
            strtolower(...),
            [...self::DEFAULT_SENSITIVE_KEYS, ...$extraSensitiveKeys],
        );
    }

    public function redact(LogRecord $record): LogRecord
    {
        $record = $record->withAttributes(new LogAttributes($this->redactArray($record->attributes->toArray())));

        return $record->withResource(new ResourceAttributes($this->redactArray($record->resource->toArray())));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function redactArray(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            $redacted[$key] = match (true) {
                \in_array(strtolower((string) $key), $this->sensitiveKeys, true) => self::REPLACEMENT,
                \is_array($value) => $this->redactArray($value),
                default => $value,
            };
        }

        return $redacted;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Redaction/AttributeRedactorTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/monolog-bundle/src/Redaction packages/monolog-bundle/tests/Redaction
git commit -m "feat(monolog-bundle): add AttributeRedactor"
```

---

## Task 5: `LogEnricherInterface` + `ResourceAttributesEnricher`

**Files:**
- Create: `packages/monolog-bundle/src/Enricher/LogEnricherInterface.php`
- Create: `packages/monolog-bundle/src/Enricher/ResourceAttributesEnricher.php`
- Test: `packages/monolog-bundle/tests/Enricher/ResourceAttributesEnricherTest.php`

**Interfaces:**
- Consumes: `Model\LogRecord`, `Model\ResourceAttributes` (Task 2)
- Produces:
  - `LogEnricherInterface { enrich(LogRecord $record): LogRecord }`
  - `ResourceAttributesEnricher implements LogEnricherInterface { __construct(string $serviceName, ?string $serviceVersion, string $environment) }`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Enricher;

use LogHub\MonologBundle\Enricher\ResourceAttributesEnricher;
use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use PHPUnit\Framework\TestCase;

final class ResourceAttributesEnricherTest extends TestCase
{
    private function record(): LogRecord
    {
        return new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: 'msg',
            channel: null,
            resource: new ResourceAttributes(),
            attributes: new LogAttributes(),
        );
    }

    public function testFillsServiceEnvironmentAndHost(): void
    {
        $enricher = new ResourceAttributesEnricher('billing-api', '1.4.2', 'prod');

        $enriched = $enricher->enrich($this->record())->resource->toArray();

        self::assertSame('billing-api', $enriched['service.name']);
        self::assertSame('1.4.2', $enriched['service.version']);
        self::assertSame('prod', $enriched['deployment.environment.name']);
        self::assertArrayHasKey('host.name', $enriched);
    }

    public function testOmitsServiceVersionWhenNull(): void
    {
        $enricher = new ResourceAttributesEnricher('billing-api', null, 'prod');

        $enriched = $enricher->enrich($this->record())->resource->toArray();

        self::assertArrayNotHasKey('service.version', $enriched);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Enricher/ResourceAttributesEnricherTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the interface and enricher**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Enricher;

use LogHub\MonologBundle\Model\LogRecord;

interface LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord;
}
```

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Enricher;

use LogHub\MonologBundle\Model\LogRecord;

final readonly class ResourceAttributesEnricher implements LogEnricherInterface
{
    public function __construct(
        private string $serviceName,
        private ?string $serviceVersion,
        private string $environment,
    ) {
    }

    public function enrich(LogRecord $record): LogRecord
    {
        $resource = $record->resource
            ->with('service.name', $this->serviceName)
            ->with('deployment.environment.name', $this->environment)
            ->with('host.name', gethostname() ?: null);

        if (null !== $this->serviceVersion) {
            $resource = $resource->with('service.version', $this->serviceVersion);
        }

        return $record->withResource($resource);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Enricher/ResourceAttributesEnricherTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/monolog-bundle/src/Enricher packages/monolog-bundle/tests/Enricher
git commit -m "feat(monolog-bundle): add LogEnricherInterface and ResourceAttributesEnricher"
```

---

## Task 6: `RequestAttributesEnricher`

**Files:**
- Create: `packages/monolog-bundle/src/Enricher/RequestAttributesEnricher.php`
- Test: `packages/monolog-bundle/tests/Enricher/RequestAttributesEnricherTest.php`

**Interfaces:**
- Consumes: `LogEnricherInterface`, `Model\LogRecord` (Task 5, Task 2); `Symfony\Component\HttpFoundation\{Request,RequestStack}`; `Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface`
- Produces: `RequestAttributesEnricher implements LogEnricherInterface { __construct(RequestStack $requestStack, ?TokenStorageInterface $tokenStorage = null) }`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Enricher;

use LogHub\MonologBundle\Enricher\RequestAttributesEnricher;
use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class RequestAttributesEnricherTest extends TestCase
{
    private function record(): LogRecord
    {
        return new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: 'msg',
            channel: null,
            resource: new ResourceAttributes(),
            attributes: new LogAttributes(),
        );
    }

    public function testNoOpWithoutCurrentRequest(): void
    {
        $enricher = new RequestAttributesEnricher(new RequestStack());

        $record = $this->record();
        $enriched = $enricher->enrich($record);

        self::assertSame($record->attributes->toArray(), $enriched->attributes->toArray());
    }

    public function testFillsRequestAttributesWhenRequestPresent(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/payments/123', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']));

        $enricher = new RequestAttributesEnricher($requestStack);

        $attributes = $enricher->enrich($this->record())->attributes->toArray();

        self::assertSame('POST', $attributes['http.request.method']);
        self::assertSame('/payments/123', $attributes['url.path']);
        self::assertSame('203.0.113.5', $attributes['client.address']);
        self::assertArrayHasKey('request.id', $attributes);
    }

    public function testFillsUserIdWhenTokenStoragePresent(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/'));

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('tanguy', null), 'main', ['ROLE_USER']));

        $enricher = new RequestAttributesEnricher($requestStack, $tokenStorage);

        $attributes = $enricher->enrich($this->record())->attributes->toArray();

        self::assertSame('tanguy', $attributes['user.id']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Enricher/RequestAttributesEnricherTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `RequestAttributesEnricher`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Enricher;

use LogHub\MonologBundle\Model\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RequestAttributesEnricher implements LogEnricherInterface
{
    private const string REQUEST_ID_ATTRIBUTE = '_loghub_request_id';

    public function __construct(
        private RequestStack $requestStack,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function enrich(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return $record;
        }

        $attributes = $record->attributes
            ->with('http.request.method', $request->getMethod())
            ->with('url.path', $request->getPathInfo())
            ->with('client.address', $request->getClientIp())
            ->with('request.id', $this->resolveRequestId($request));

        $userId = $this->resolveUserId();
        if (null !== $userId) {
            $attributes = $attributes->with('user.id', $userId);
        }

        return $record->withAttributes($attributes);
    }

    private function resolveRequestId(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $requestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        if (\is_string($requestId)) {
            return $requestId;
        }

        $requestId = Uuid::v4()->toRfc4122();
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);

        return $requestId;
    }

    private function resolveUserId(): ?string
    {
        $token = $this->tokenStorage?->getToken();

        return $token?->getUser()?->getUserIdentifier();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Enricher/RequestAttributesEnricherTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/monolog-bundle/src/Enricher/RequestAttributesEnricher.php packages/monolog-bundle/tests/Enricher/RequestAttributesEnricherTest.php
git commit -m "feat(monolog-bundle): add RequestAttributesEnricher"
```

---

## Task 7: `BatchTransportInterface` + `HttpBatchTransport`

**Files:**
- Create: `packages/monolog-bundle/src/Transport/BatchTransportInterface.php`
- Create: `packages/monolog-bundle/src/Transport/HttpBatchTransport.php`
- Test: `packages/monolog-bundle/tests/Transport/HttpBatchTransportTest.php`

**Interfaces:**
- Consumes: `Model\LogRecord` (Task 2); `Symfony\Contracts\HttpClient\HttpClientInterface`
- Produces:
  - `BatchTransportInterface { send(array $records): void }`
  - `HttpBatchTransport implements BatchTransportInterface { __construct(HttpClientInterface $httpClient, string $endpoint, string $apiKey, float $timeout = 0.5) }`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Transport;

use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use LogHub\MonologBundle\Transport\HttpBatchTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpBatchTransportTest extends TestCase
{
    private function record(): LogRecord
    {
        return new LogRecord(
            timestamp: new \DateTimeImmutable('2026-09-09T14:45:01.231+00:00'),
            severity: new Severity('ERROR', 17),
            message: 'Payment failed',
            channel: 'payment',
            resource: new ResourceAttributes(['service.name' => 'billing-api']),
            attributes: new LogAttributes(['payment_id' => 123]),
        );
    }

    public function testSendPostsExpectedPayloadAndAuth(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [$method, $url, $options];

            return new MockResponse('{"accepted":1,"rejected":0}', ['http_code' => 202]);
        });

        $transport = new HttpBatchTransport($client, 'https://loghub.example/api/v1/logs', 'lh_secret', 0.5);
        $transport->send([$this->record()]);

        [$method, $url, $options] = $captured;

        self::assertSame('POST', $method);
        self::assertSame('https://loghub.example/api/v1/logs', $url);
        self::assertStringContainsString('Bearer lh_secret', $options['normalized_headers']['authorization'][0]);

        $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Payment failed', $body['records'][0]['message']);
        self::assertSame('billing-api', $body['records'][0]['resource']['service.name']);
    }

    public function testSendSwallowsTransportFailure(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });

        $transport = new HttpBatchTransport($client, 'https://loghub.example/api/v1/logs', 'lh_secret');

        $transport->send([$this->record()]);

        $this->addToAssertionCount(1);
    }

    public function testSendSwallowsHttpErrorStatus(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 500]));

        $transport = new HttpBatchTransport($client, 'https://loghub.example/api/v1/logs', 'lh_secret');

        $transport->send([$this->record()]);

        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Transport/HttpBatchTransportTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the interface and transport**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Transport;

interface BatchTransportInterface
{
    /** @param list<\LogHub\MonologBundle\Model\LogRecord> $records */
    public function send(array $records): void;
}
```

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Transport;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpBatchTransport implements BatchTransportInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint,
        private string $apiKey,
        private float $timeout = 0.5,
    ) {
    }

    public function send(array $records): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'auth_bearer' => $this->apiKey,
                'json' => ['records' => $records],
                'timeout' => $this->timeout,
            ]);

            // Force completion of the transfer so a transport-level failure surfaces here (fail-open, spec 3.7/8.5).
            $response->getStatusCode();
        } catch (\Throwable $e) {
            error_log(sprintf('[loghub] failed to ship %d log record(s): %s', \count($records), $e->getMessage()));
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Transport/HttpBatchTransportTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/monolog-bundle/src/Transport packages/monolog-bundle/tests/Transport
git commit -m "feat(monolog-bundle): add BatchTransportInterface and fail-open HttpBatchTransport"
```

---

## Task 8: `LogBuffer`

**Files:**
- Create: `packages/monolog-bundle/src/Buffer/LogBuffer.php`
- Test: `packages/monolog-bundle/tests/Buffer/LogBufferTest.php`

**Interfaces:**
- Consumes: `BatchTransportInterface`, `Model\LogRecord` (Task 7, Task 2)
- Produces: `LogBuffer { __construct(BatchTransportInterface $transport, int $maxRecords = 100, int $maxBytes = 1_000_000); add(LogRecord $record): void; flush(): void }`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Buffer;

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
use PHPUnit\Framework\TestCase;

final class RecordingTransport implements BatchTransportInterface
{
    /** @var list<array> */
    public array $calls = [];

    public function send(array $records): void
    {
        $this->calls[] = $records;
    }
}

final class LogBufferTest extends TestCase
{
    private function record(string $message = 'msg'): LogRecord
    {
        return new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: $message,
            channel: null,
            resource: new ResourceAttributes(),
            attributes: new LogAttributes(),
        );
    }

    public function testFlushIsNoOpWhenEmpty(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport);

        $buffer->flush();

        self::assertSame([], $transport->calls);
    }

    public function testAddDoesNotFlushBeforeLimitReached(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport, maxRecords: 3);

        $buffer->add($this->record());
        $buffer->add($this->record());

        self::assertSame([], $transport->calls);
    }

    public function testFlushesWhenRecordLimitReached(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport, maxRecords: 2);

        $buffer->add($this->record('a'));
        $buffer->add($this->record('b'));
        $buffer->add($this->record('c'));

        self::assertCount(1, $transport->calls);
        self::assertCount(2, $transport->calls[0]);
    }

    public function testFlushesWhenByteLimitReached(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport, maxRecords: 1000, maxBytes: 1);

        $buffer->add($this->record('this record alone already exceeds one byte'));

        self::assertCount(1, $transport->calls);
    }

    public function testManualFlushClearsBufferRegardlessOfTransportOutcome(): void
    {
        $transport = new RecordingTransport();
        $buffer = new LogBuffer($transport, maxRecords: 1000);

        $buffer->add($this->record());
        $buffer->flush();
        $buffer->flush();

        self::assertCount(1, $transport->calls);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Buffer/LogBufferTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `LogBuffer`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Buffer;

use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Transport\BatchTransportInterface;

final class LogBuffer
{
    /** @var list<LogRecord> */
    private array $records = [];

    private int $bytes = 0;

    public function __construct(
        private readonly BatchTransportInterface $transport,
        private readonly int $maxRecords = 100,
        private readonly int $maxBytes = 1_000_000,
    ) {
    }

    public function add(LogRecord $record): void
    {
        $size = \strlen(json_encode($record, JSON_THROW_ON_ERROR));

        if (\count($this->records) >= $this->maxRecords || ($this->records && $this->bytes + $size > $this->maxBytes)) {
            $this->flush();
        }

        $this->records[] = $record;
        $this->bytes += $size;
    }

    public function flush(): void
    {
        if ([] === $this->records) {
            return;
        }

        $records = $this->records;
        $this->records = [];
        $this->bytes = 0;

        $this->transport->send($records);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Buffer/LogBufferTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/monolog-bundle/src/Buffer packages/monolog-bundle/tests/Buffer
git commit -m "feat(monolog-bundle): add bounded LogBuffer"
```

---

## Task 9: `LogHubHandler`

**Files:**
- Create: `packages/monolog-bundle/src/Handler/LogHubHandler.php`
- Test: `packages/monolog-bundle/tests/Handler/LogHubHandlerTest.php`

**Interfaces:**
- Consumes: `MonologRecordMapper` (Task 3), `LogEnricherInterface` (Task 5), `AttributeRedactor` (Task 4), `LogBuffer` (Task 8)
- Produces: `LogHubHandler extends \Monolog\Handler\AbstractProcessingHandler { __construct(MonologRecordMapper $mapper, iterable $enrichers, AttributeRedactor $redactor, LogBuffer $buffer, int|string|Level $level = Level::Info, bool $bubble = true); flush(): void }`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Handler;

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\Enricher\LogEnricherInterface;
use LogHub\MonologBundle\Handler\LogHubHandler;
use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class SpyTransport implements BatchTransportInterface
{
    /** @var list<array> */
    public array $calls = [];

    public function send(array $records): void
    {
        $this->calls[] = $records;
    }
}

final class BrokenEnricher implements LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord
    {
        throw new \RuntimeException('enricher exploded');
    }
}

final class UppercaseMessageEnricher implements LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord
    {
        return $record->withAttributes($record->attributes->with('enriched', true));
    }
}

final class LogHubHandlerTest extends TestCase
{
    public function testRecordFlowsThroughFullPipeline(): void
    {
        $transport = new SpyTransport();
        $handler = new LogHubHandler(
            new MonologRecordMapper(),
            [new UppercaseMessageEnricher()],
            new AttributeRedactor(),
            new LogBuffer($transport, maxRecords: 1000),
        );

        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $logger->info('hello', ['password' => 'secret']);
        $handler->flush();

        self::assertCount(1, $transport->calls);
        /** @var LogRecord $record */
        $record = $transport->calls[0][0];
        self::assertSame('hello', $record->message);
        self::assertTrue($record->attributes->toArray()['enriched']);
        self::assertSame('[REDACTED]', $record->attributes->toArray()['password']);
    }

    public function testBrokenEnricherDoesNotPropagateOrBreakBuffering(): void
    {
        $transport = new SpyTransport();
        $handler = new LogHubHandler(
            new MonologRecordMapper(),
            [new BrokenEnricher()],
            new AttributeRedactor(),
            new LogBuffer($transport, maxRecords: 1000),
        );

        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $logger->info('this must not throw');
        $handler->flush();

        self::assertSame([], $transport->calls);
    }

    public function testLevelFilterAppliesBeforeHandling(): void
    {
        $transport = new SpyTransport();
        $handler = new LogHubHandler(
            new MonologRecordMapper(),
            [],
            new AttributeRedactor(),
            new LogBuffer($transport, maxRecords: 1000),
            level: Level::Warning,
        );

        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $logger->info('below threshold, ignored');
        $handler->flush();

        self::assertSame([], $transport->calls);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Handler/LogHubHandlerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `LogHubHandler`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Handler;

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\Enricher\LogEnricherInterface;
use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord as MonologLogRecord;

final class LogHubHandler extends AbstractProcessingHandler
{
    /** @param iterable<LogEnricherInterface> $enrichers */
    public function __construct(
        private readonly MonologRecordMapper $mapper,
        private readonly iterable $enrichers,
        private readonly AttributeRedactor $redactor,
        private readonly LogBuffer $buffer,
        int|string|Level $level = Level::Info,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        register_shutdown_function($this->flush(...));
    }

    protected function write(MonologLogRecord $record): void
    {
        try {
            $mapped = $this->mapper->map($record);

            foreach ($this->enrichers as $enricher) {
                $mapped = $enricher->enrich($mapped);
            }

            $mapped = $this->redactor->redact($mapped);

            $this->buffer->add($mapped);
        } catch (\Throwable) {
            // Fail-open (spec 3.7/8.5): a broken pipeline stage must never break application logging,
            // and must never recursively log to LogHub itself.
        }
    }

    public function flush(): void
    {
        $this->buffer->flush();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/Handler/LogHubHandlerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/monolog-bundle/src/Handler packages/monolog-bundle/tests/Handler
git commit -m "feat(monolog-bundle): add LogHubHandler wiring the full pipeline, fail-open"
```

---

## Task 10: Bundle + DependencyInjection wiring

**Files:**
- Create: `packages/monolog-bundle/src/LogHubMonologBundle.php`
- Create: `packages/monolog-bundle/src/DependencyInjection/Configuration.php`
- Create: `packages/monolog-bundle/src/DependencyInjection/LogHubMonologExtension.php`
- Create: `packages/monolog-bundle/config/services.php`
- Test: `packages/monolog-bundle/tests/DependencyInjection/LogHubMonologExtensionTest.php`

**Interfaces:**
- Consumes: every service class from Tasks 1-9
- Produces: container built from `loghub:` config key, service ids `loghub.record_mapper` (`MonologRecordMapper`), `loghub.redactor` (`AttributeRedactor`), `loghub.resource_enricher`, `loghub.request_enricher`, `loghub.transport` (aliased to `BatchTransportInterface`), `loghub.buffer` (`LogBuffer`), `loghub.monolog_handler` (public `LogHubHandler`, matching the spec 3.1 example service id)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\DependencyInjection;

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\DependencyInjection\LogHubMonologExtension;
use LogHub\MonologBundle\Enricher\ResourceAttributesEnricher;
use LogHub\MonologBundle\Handler\LogHubHandler;
use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
use LogHub\MonologBundle\Transport\HttpBatchTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class LogHubMonologExtensionTest extends TestCase
{
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test']));
        $container->registerExtension($extension = new LogHubMonologExtension());
        $container->loadFromExtension($extension->getAlias(), array_merge([
            'endpoint' => 'https://loghub.example/api/v1/logs',
            'api_key' => 'lh_secret',
            'service' => ['name' => 'billing-api'],
        ], $config));
        $container->compile();

        return $container;
    }

    public function testAliasIsLoghub(): void
    {
        self::assertSame('loghub', (new LogHubMonologExtension())->getAlias());
    }

    public function testContainerCompilesWithExpectedServices(): void
    {
        $container = $this->buildContainer();

        self::assertTrue($container->has(MonologRecordMapper::class));
        self::assertTrue($container->has(AttributeRedactor::class));
        self::assertTrue($container->has(ResourceAttributesEnricher::class));
        self::assertTrue($container->has(HttpBatchTransport::class));
        self::assertTrue($container->has(BatchTransportInterface::class));
        self::assertTrue($container->has(LogBuffer::class));
        self::assertTrue($container->has(LogHubHandler::class));
    }

    public function testParametersAreMappedFromConfig(): void
    {
        $container = $this->buildContainer([
            'service' => ['name' => 'billing-api', 'version' => '1.4.2'],
            'batch' => ['max_records' => 50, 'max_bytes' => 2000],
        ]);

        self::assertSame('https://loghub.example/api/v1/logs', $container->getParameter('loghub.endpoint'));
        self::assertSame('lh_secret', $container->getParameter('loghub.api_key'));
        self::assertSame('billing-api', $container->getParameter('loghub.service_name'));
        self::assertSame('1.4.2', $container->getParameter('loghub.service_version'));
        self::assertSame(50, $container->getParameter('loghub.batch_max_records'));
        self::assertSame(2000, $container->getParameter('loghub.batch_max_bytes'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/DependencyInjection/LogHubMonologExtensionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the bundle class**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class LogHubMonologBundle extends Bundle
{
}
```

- [ ] **Step 4: Implement `Configuration`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('loghub');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('endpoint')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('api_key')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('environment')->defaultValue('%kernel.environment%')->end()
                ->floatNode('timeout')->defaultValue(0.5)->end()
                ->arrayNode('service')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('version')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('batch')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_records')->defaultValue(100)->end()
                        ->integerNode('max_bytes')->defaultValue(1_000_000)->end()
                    ->end()
                ->end()
                ->arrayNode('redaction')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('extra_keys')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
```

- [ ] **Step 5: Implement `LogHubMonologExtension`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\DependencyInjection;

use LogHub\MonologBundle\Enricher\LogEnricherInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class LogHubMonologExtension extends Extension
{
    public function getAlias(): string
    {
        return 'loghub';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        $container->setParameter('loghub.endpoint', $config['endpoint']);
        $container->setParameter('loghub.api_key', $config['api_key']);
        $container->setParameter('loghub.environment', $config['environment']);
        $container->setParameter('loghub.timeout', $config['timeout']);
        $container->setParameter('loghub.service_name', $config['service']['name']);
        $container->setParameter('loghub.service_version', $config['service']['version']);
        $container->setParameter('loghub.batch_max_records', $config['batch']['max_records']);
        $container->setParameter('loghub.batch_max_bytes', $config['batch']['max_bytes']);
        $container->setParameter('loghub.redaction_extra_keys', $config['redaction']['extra_keys']);

        $container->registerForAutoconfiguration(LogEnricherInterface::class)
            ->addTag('loghub.enricher');
    }
}
```

- [ ] **Step 6: Implement `config/services.php`**

```php
<?php

declare(strict_types=1);

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\Enricher\RequestAttributesEnricher;
use LogHub\MonologBundle\Enricher\ResourceAttributesEnricher;
use LogHub\MonologBundle\Handler\LogHubHandler;
use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
use LogHub\MonologBundle\Transport\HttpBatchTransport;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(MonologRecordMapper::class);

    $services->set(AttributeRedactor::class)
        ->arg('$extraSensitiveKeys', '%loghub.redaction_extra_keys%');

    $services->set(ResourceAttributesEnricher::class)
        ->arg('$serviceName', '%loghub.service_name%')
        ->arg('$serviceVersion', '%loghub.service_version%')
        ->arg('$environment', '%loghub.environment%')
        ->tag('loghub.enricher');

    $services->set(RequestAttributesEnricher::class)
        ->tag('loghub.enricher');

    $services->set(HttpBatchTransport::class)
        ->arg('$endpoint', '%loghub.endpoint%')
        ->arg('$apiKey', '%loghub.api_key%')
        ->arg('$timeout', '%loghub.timeout%');

    $services->alias(BatchTransportInterface::class, HttpBatchTransport::class);

    $services->set(LogBuffer::class)
        ->arg('$transport', service(BatchTransportInterface::class))
        ->arg('$maxRecords', '%loghub.batch_max_records%')
        ->arg('$maxBytes', '%loghub.batch_max_bytes%');

    $services->set(LogHubHandler::class)
        ->arg('$enrichers', tagged_iterator('loghub.enricher'))
        ->public();

    $services->alias('loghub.monolog_handler', LogHubHandler::class)->public();
};
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/DependencyInjection/LogHubMonologExtensionTest.php`
Expected: PASS

If it fails on `LogBuffer`'s constructor argument name, check Task 8's `LogBuffer::__construct` parameter is literally `$transport` (it is) — `service(BatchTransportInterface::class)` is only needed if autowiring the interface doesn't resolve via the alias; since `$services->alias(BatchTransportInterface::class, HttpBatchTransport::class)` is registered, autowiring `LogBuffer`'s `BatchTransportInterface $transport` parameter works without the explicit `->arg(...)` — simplify by deleting that line if the test passes without it.

- [ ] **Step 8: Run the full package test suite**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit`
Expected: PASS (all tests from Tasks 1-10)

- [ ] **Step 9: Commit**

```bash
git add packages/monolog-bundle/src/LogHubMonologBundle.php packages/monolog-bundle/src/DependencyInjection packages/monolog-bundle/config packages/monolog-bundle/tests/DependencyInjection
git commit -m "feat(monolog-bundle): add bundle class and DI wiring for full pipeline"
```

---

## Task 11: `FlushOnTerminateListener`

**Files:**
- Create: `packages/monolog-bundle/src/EventListener/FlushOnTerminateListener.php`
- Modify: `packages/monolog-bundle/config/services.php` (register the listener)
- Test: `packages/monolog-bundle/tests/EventListener/FlushOnTerminateListenerTest.php`

**Interfaces:**
- Consumes: `LogHubHandler` (Task 9)
- Produces: `FlushOnTerminateListener { __construct(LogHubHandler $handler); onTerminate(): void }`, listening on `kernel.terminate` and `console.terminate`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\EventListener;

use LogHub\MonologBundle\Buffer\LogBuffer;
use LogHub\MonologBundle\EventListener\FlushOnTerminateListener;
use LogHub\MonologBundle\Handler\LogHubHandler;
use LogHub\MonologBundle\Mapper\MonologRecordMapper;
use LogHub\MonologBundle\Redaction\AttributeRedactor;
use LogHub\MonologBundle\Transport\BatchTransportInterface;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class SpyTransport implements BatchTransportInterface
{
    public int $sentBatches = 0;

    public function send(array $records): void
    {
        ++$this->sentBatches;
    }
}

final class FlushOnTerminateListenerTest extends TestCase
{
    public function testOnTerminateFlushesTheHandler(): void
    {
        $transport = new SpyTransport();
        $handler = new LogHubHandler(
            new MonologRecordMapper(),
            [],
            new AttributeRedactor(),
            new LogBuffer($transport, maxRecords: 1000),
        );

        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $logger->info('buffered, not yet sent');

        self::assertSame(0, $transport->sentBatches);

        (new FlushOnTerminateListener($handler))->onTerminate();

        self::assertSame(1, $transport->sentBatches);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/EventListener/FlushOnTerminateListenerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `FlushOnTerminateListener`**

```php
<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\EventListener;

use LogHub\MonologBundle\Handler\LogHubHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class FlushOnTerminateListener
{
    public function __construct(
        private LogHubHandler $handler,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function onTerminate(): void
    {
        $this->handler->flush();
    }
}
```

Note: relies on the host application having `symfony/framework-bundle` register `#[AsEventListener]` autoconfiguration on the container — true for any full-stack Symfony app, which is this bundle's only supported target (spec 3.1).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit tests/EventListener/FlushOnTerminateListenerTest.php`
Expected: PASS

- [ ] **Step 5: Register the listener in `config/services.php`**

Add, right after the `loghub.monolog_handler` alias:

```php
    $services->set(\LogHub\MonologBundle\EventListener\FlushOnTerminateListener::class);
```

(Add `use LogHub\MonologBundle\EventListener\FlushOnTerminateListener;` to the file's `use` block instead of the FQCN if preferred — keep consistent with the file's existing style.)

- [ ] **Step 6: Run the full package test suite**

Run: `cd packages/monolog-bundle && vendor/bin/phpunit`
Expected: PASS (all)

- [ ] **Step 7: Commit**

```bash
git add packages/monolog-bundle/src/EventListener packages/monolog-bundle/tests/EventListener packages/monolog-bundle/config/services.php
git commit -m "feat(monolog-bundle): flush buffer on kernel/console terminate"
```

---

## Task 12: Root composer.json wiring

**Files:**
- Modify: `/Users/tanguylemarie/YouTube/LogHub/composer.json`

**Interfaces:**
- Consumes: `packages/monolog-bundle` (Tasks 1-11)
- Produces: root project depends on `loghub/monolog-bundle` via a path repository, proving the package is installable the way a consuming application would install it.

- [ ] **Step 1: Add the path repository and requirement**

Edit root `composer.json`: add a top-level `"repositories"` key (after `"license"`/before `"require"` is fine) and add the package to `"require"`:

```json
    "repositories": [
        {
            "type": "path",
            "url": "packages/monolog-bundle"
        }
    ],
```

Add to `"require"`:

```json
        "loghub/monolog-bundle": "*",
```

- [ ] **Step 2: Update the lock file**

Run: `composer update loghub/monolog-bundle`
Expected: succeeds, `loghub/monolog-bundle` (symlinked, path repo) appears in `composer.lock` and `vendor/loghub/monolog-bundle`.

- [ ] **Step 3: Verify the package resolves from the root app**

Run: `composer show loghub/monolog-bundle`
Expected: shows the package, version `dev-main` or similar path-repo marker, source type `path`.

Run: `php -r "require 'vendor/autoload.php'; var_dump(class_exists(\LogHub\MonologBundle\LogHubMonologBundle::class));"`
Expected: `bool(true)`

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: require loghub/monolog-bundle as a path-repository dependency"
```

---

## Post-plan verification

- [ ] Run `cd packages/monolog-bundle && vendor/bin/phpunit` — full package suite green.
- [ ] Run `composer validate --strict` in both `packages/monolog-bundle/` and the repo root — no warnings.
- [ ] Confirm `git log --oneline -12` shows one commit per task, in order.

This plan does not touch `config/bundles.php`, `config/packages/monolog.yaml`, or any file under `src/` at the root — enabling the bundle inside the LogHub server app itself (dogfooding) is out of scope (design doc section 9) and would be a separate, later task.
