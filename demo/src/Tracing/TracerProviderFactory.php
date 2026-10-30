<?php

namespace App\Tracing;

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
    public static function create(string $endpoint, string $apiKey, string $serviceName, string $environment): TracerProviderInterface
    {
        // Short timeout, one retry: spans are flushed on kernel.terminate, which the built-in server runs before closing the response.
        $transport = (new OtlpHttpTransportFactory())->create(
            $endpoint,
            ContentTypes::PROTOBUF,
            ['Authorization' => 'Bearer '.$apiKey],
            timeout: 2.,
            maxRetries: 1,
        );

        return TracerProvider::builder()
            ->addSpanProcessor(BatchSpanProcessor::builder(new SpanExporter($transport))->build())
            ->setResource(ResourceInfo::create(Attributes::create([
                'service.name' => $serviceName,
                'deployment.environment.name' => $environment,
            ])))
            ->build();
    }
}
