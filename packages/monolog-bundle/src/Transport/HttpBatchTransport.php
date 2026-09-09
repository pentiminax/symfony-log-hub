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
