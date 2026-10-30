<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Transport;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpBatchTransport implements BatchTransportInterface
{
    private string $endpoint;

    public function __construct(
        private HttpClientInterface $httpClient,
        string $url,
        private ?string $apiKey,
        private float $timeout = 0.5,
    ) {
        // Normalized here, not in the extension: `url` is usually an env var only known at runtime.
        $this->endpoint = rtrim($url, '/').'/api/v1/logs';
    }

    public function send(array $records): void
    {
        // No project key configured: log shipping is disabled.
        if (null === $this->apiKey || '' === $this->apiKey) {
            return;
        }

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
