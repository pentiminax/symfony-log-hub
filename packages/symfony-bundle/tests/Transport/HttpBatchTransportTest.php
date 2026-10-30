<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\Transport;

use LogHub\SymfonyBundle\Model\LogAttributes;
use LogHub\SymfonyBundle\Model\LogRecord;
use LogHub\SymfonyBundle\Model\ResourceAttributes;
use LogHub\SymfonyBundle\Model\Severity;
use LogHub\SymfonyBundle\Transport\HttpBatchTransport;
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

    public function testSendIsANoOpWithoutApiKey(): void
    {
        $client = new MockHttpClient(static fn () => self::fail('must not send without an api key'));

        (new HttpBatchTransport($client, 'https://loghub.example', null))->send([$this->record()]);
        (new HttpBatchTransport($client, 'https://loghub.example', ''))->send([$this->record()]);

        self::assertSame(0, $client->getRequestsCount());
    }

    public function testSendPostsExpectedPayloadAndAuth(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [$method, $url, $options];

            return new MockResponse('{"accepted":1,"rejected":0}', ['http_code' => 202]);
        });

        $transport = new HttpBatchTransport($client, 'https://loghub.example', 'lh_secret', 0.5);
        $transport->send([$this->record()]);

        [$method, $url, $options] = $captured;

        self::assertSame('POST', $method);
        self::assertSame('https://loghub.example/api/v1/logs', $url);
        self::assertStringContainsString('Bearer lh_secret', $options['normalized_headers']['authorization'][0]);

        $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Payment failed', $body['records'][0]['message']);
        self::assertSame('billing-api', $body['records'][0]['resource']['service.name']);
    }

    public function testUrlWithTrailingSlashIsNormalized(): void
    {
        $urls = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('', ['http_code' => 202]);
        });

        (new HttpBatchTransport($client, 'https://loghub.example/', 'lh_secret'))->send([$this->record()]);
        (new HttpBatchTransport($client, 'https://loghub.example/prefix//', 'lh_secret'))->send([$this->record()]);

        self::assertSame(['https://loghub.example/api/v1/logs', 'https://loghub.example/prefix/api/v1/logs'], $urls);
    }

    public function testSendSwallowsTransportFailure(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });

        $transport = new HttpBatchTransport($client, 'https://loghub.example', 'lh_secret');

        $transport->send([$this->record()]);

        $this->addToAssertionCount(1);
    }

    public function testSendSwallowsHttpErrorStatus(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 500]));

        $transport = new HttpBatchTransport($client, 'https://loghub.example', 'lh_secret');

        $transport->send([$this->record()]);

        $this->addToAssertionCount(1);
    }
}
