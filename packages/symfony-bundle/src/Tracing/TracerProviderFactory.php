<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tracing;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

final class TracerProviderFactory
{
    public static function create(string $url, ?string $apiKey, string $serviceName, ?string $serviceVersion, string $environment): TracerProviderInterface
    {
        $builder = TracerProvider::builder()->setResource(ResourceInfo::create(Attributes::create(array_filter([
            'service.name' => $serviceName,
            'service.version' => $serviceVersion,
            'deployment.environment.name' => $environment,
        ]))));

        // No project key: a provider without processor exports nothing (spans still get ids, so log correlation keeps working).
        if (null === $apiKey || '' === $apiKey) {
            return $builder->build();
        }

        // Short timeout, one retry: spans are flushed on kernel.terminate, before the connection is closed.
        $transport = (new OtlpHttpTransportFactory())->create(
            self::endpoint($url),
            ContentTypes::PROTOBUF,
            ['Authorization' => 'Bearer '.$apiKey],
            timeout: 2.,
            maxRetries: 1,
        );

        return $builder->addSpanProcessor(BatchSpanProcessor::builder(new SpanExporter($transport))->build())->build();
    }

    /**
     * Normalized at runtime, not in the extension: `url` is usually an env var only known then.
     */
    public static function endpoint(string $url): string
    {
        return rtrim($url, '/').'/v1/traces';
    }
}
