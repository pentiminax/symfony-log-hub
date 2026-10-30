<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Enricher;

use LogHub\MonologBundle\Model\LogRecord;
use OpenTelemetry\API\Trace\Span;

final readonly class TraceContextEnricher implements LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord
    {
        if (null !== $record->traceId) {
            return $record;
        }

        $context = Span::getCurrent()->getContext();

        return $context->isValid()
            ? $record->withTrace($context->getTraceId(), $context->getSpanId())
            : $record;
    }
}
