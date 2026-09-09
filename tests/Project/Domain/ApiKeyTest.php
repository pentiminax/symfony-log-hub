<?php

declare(strict_types=1);

namespace App\Tests\Project\Domain;

use App\Log\Domain\ProjectId;
use App\Project\Domain\ApiKey;
use App\Project\Domain\ApiKeyId;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testVerifyAcceptsTheMatchingPlaintext(): void
    {
        $apiKey = ApiKey::issue(ApiKeyId::generate(), ProjectId::generate(), ApiKey::hash('lh_secret', 'pepper'), 'default');

        self::assertTrue($apiKey->verify('lh_secret', 'pepper'));
    }

    public function testVerifyRejectsAWrongPlaintext(): void
    {
        $apiKey = ApiKey::issue(ApiKeyId::generate(), ProjectId::generate(), ApiKey::hash('lh_secret', 'pepper'), 'default');

        self::assertFalse($apiKey->verify('lh_not-the-secret', 'pepper'));
    }

    public function testVerifyRejectsTheRightPlaintextUnderAWrongPepper(): void
    {
        $apiKey = ApiKey::issue(ApiKeyId::generate(), ProjectId::generate(), ApiKey::hash('lh_secret', 'pepper'), 'default');

        self::assertFalse($apiKey->verify('lh_secret', 'other-pepper'));
    }

    public function testNewKeyIsNotRevoked(): void
    {
        $apiKey = ApiKey::issue(ApiKeyId::generate(), ProjectId::generate(), 'hash', 'default');

        self::assertFalse($apiKey->isRevoked());
    }

    public function testRevokeMarksTheKeyAsRevoked(): void
    {
        $apiKey = ApiKey::issue(ApiKeyId::generate(), ProjectId::generate(), 'hash', 'default');

        $revoked = $apiKey->revoke();

        self::assertTrue($revoked->isRevoked());
        self::assertNotNull($revoked->revokedAt);
    }

    public function testRevokeIsIdempotent(): void
    {
        $apiKey = ApiKey::issue(ApiKeyId::generate(), ProjectId::generate(), 'hash', 'default');

        $firstRevocation = $apiKey->revoke();
        $secondRevocation = $firstRevocation->revoke();

        self::assertSame($firstRevocation->revokedAt, $secondRevocation->revokedAt);
    }

    public function testRevokeDoesNotMutateTheOriginalInstance(): void
    {
        $apiKey = ApiKey::issue(ApiKeyId::generate(), ProjectId::generate(), 'hash', 'default');

        $apiKey->revoke();

        self::assertFalse($apiKey->isRevoked());
    }
}
