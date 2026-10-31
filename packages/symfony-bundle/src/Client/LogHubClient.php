<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Client;

use LogHub\SymfonyBundle\Exception\EngineException;
use LogHub\SymfonyBundle\Exception\EngineUnauthorizedException;
use LogHub\SymfonyBundle\Exception\EngineUnavailableException;
use LogHub\SymfonyBundle\Exception\InvalidEngineRequestException;
use LogHub\SymfonyBundle\Model\ApiKey;
use LogHub\SymfonyBundle\Model\CreatedApiKey;
use LogHub\SymfonyBundle\Model\Project;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for the engine's internal API (`/v1`, master key). The http client is
 * expected to carry the base URI and the bearer header.
 */
final readonly class LogHubClient
{
    public function __construct(private HttpClientInterface $http)
    {
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    public function createProject(string $name): Project
    {
        return Project::fromArray(
            $this->request('POST', '/v1/projects', ['json' => ['name' => $name]])
        );
    }

    /** @return list<Project> */
    public function listProjects(): array
    {
        return array_map(Project::fromArray(...), $this->request('GET', '/v1/projects'));
    }

    public function getProject(string $id): ?Project
    {
        $data = $this->request('GET', '/v1/projects/'.rawurlencode($id), notFoundIsNull: true);

        return null === $data ? null : Project::fromArray($data);
    }

    public function createApiKey(string $projectId, string $label): CreatedApiKey
    {
        return CreatedApiKey::fromArray(
            $this->request('POST', $this->keysPath($projectId), ['json' => ['label' => $label]]),
        );
    }

    /** @return list<ApiKey> */
    public function listApiKeys(string $projectId): array
    {
        return array_map(ApiKey::fromArray(...), $this->request('GET', $this->keysPath($projectId)));
    }

    public function revokeApiKey(string $projectId, string $keyId): ApiKey
    {
        return ApiKey::fromArray($this->request('POST', $this->keysPath($projectId).'/'.rawurlencode($keyId).'/revoke'));
    }

    /**
     * @param array<string, mixed> $body engine search request (range, search, filters, sort, limit, cursor)
     *
     * @return array{records: list<array<string, mixed>>, next_cursor: array{timestamp: string, id: string}|null, has_more: bool}
     */
    public function searchLogs(string $projectId, array $body): array
    {
        return $this->request('POST', $this->logsPath($projectId).'/search', ['json' => (object) $body]);
    }

    /**
     * @param array<string, mixed> $body same as {@see searchLogs()}, plus an optional "max"
     */
    public function countLogs(string $projectId, array $body): int
    {
        return $this->request('POST', $this->logsPath($projectId).'/count', ['json' => (object) $body])['count'];
    }

    /** @return list<string> */
    public function logFacets(string $projectId, string $field, ?string $from = null, ?string $to = null): array
    {
        return $this->request('GET', $this->logsPath($projectId).'/facets/'.rawurlencode($field), [
            'query' => array_filter(['from' => $from, 'to' => $to], static fn (?string $v): bool => null !== $v),
        ])['values'];
    }

    /** @return array<string, mixed>|null */
    public function findLog(string $projectId, string $logId): ?array
    {
        return $this->request('GET', $this->logsPath($projectId).'/'.rawurlencode($logId), notFoundIsNull: true);
    }

    /**
     * @param array<string, mixed> $body engine trace search request (range, filters, limit, cursor)
     *
     * @return array{traces: list<array<string, mixed>>, next_cursor: array{start_time: string, trace_id: string}|null, has_more: bool}
     */
    public function searchTraces(string $projectId, array $body): array
    {
        return $this->request('POST', $this->tracesPath($projectId).'/search', ['json' => (object) $body]);
    }

    /** @return array{trace_id: string, spans: list<array<string, mixed>>}|null */
    public function getTrace(string $projectId, string $traceId): ?array
    {
        return $this->request('GET', $this->tracesPath($projectId).'/'.rawurlencode($traceId), notFoundIsNull: true);
    }

    private function tracesPath(string $projectId): string
    {
        return '/v1/projects/'.rawurlencode($projectId).'/traces';
    }

    private function keysPath(string $projectId): string
    {
        return '/v1/projects/'.rawurlencode($projectId).'/keys';
    }

    private function logsPath(string $projectId): string
    {
        return '/v1/projects/'.rawurlencode($projectId).'/logs';
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return ($notFoundIsNull is true ? array<mixed>|null : array<mixed>)
     */
    private function request(string $method, string $path, array $options = [], bool $notFoundIsNull = false): ?array
    {
        try {
            $response = $this->http->request($method, $path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new EngineUnavailableException('The LogHub engine is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($notFoundIsNull && 404 === $status) {
            return null;
        }

        if ($status >= 200 && $status < 300) {
            try {
                return json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new EngineException('The LogHub engine returned invalid JSON.', previous: $e);
            }
        }

        $error = json_decode($content, true);
        $message = \is_array($error) ? (string) ($error['message'] ?? $error['error'] ?? '') : '';
        $message = '' !== $message ? $message : sprintf('HTTP %d', $status);

        throw match (true) {
            401 === $status => new EngineUnauthorizedException('The LogHub engine rejected the master key.'),
            400 === $status || 422 === $status => new InvalidEngineRequestException($message),
            $status >= 500 => new EngineUnavailableException(sprintf('The LogHub engine failed (%d): %s', $status, $message)),
            default => new EngineException(sprintf('Unexpected LogHub engine response (%d): %s', $status, $message)),
        };
    }
}
