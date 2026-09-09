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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
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
