<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Redaction;

use LogHub\SymfonyBundle\Model\LogAttributes;
use LogHub\SymfonyBundle\Model\LogRecord;
use LogHub\SymfonyBundle\Model\ResourceAttributes;
use LogHub\SymfonyBundle\Model\Severity;
use LogHub\SymfonyBundle\Redaction\AttributeRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('defaultSensitiveKeys')]
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
