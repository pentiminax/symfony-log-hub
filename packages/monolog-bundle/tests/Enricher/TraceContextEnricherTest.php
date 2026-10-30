<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\Enricher;

use LogHub\MonologBundle\Enricher\TraceContextEnricher;
use LogHub\MonologBundle\Model\LogAttributes;
use LogHub\MonologBundle\Model\LogRecord;
use LogHub\MonologBundle\Model\ResourceAttributes;
use LogHub\MonologBundle\Model\Severity;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use PHPUnit\Framework\TestCase;

final class TraceContextEnricherTest extends TestCase
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';
    private const string SPAN_ID = 'b7ad6b7169203331';

    private function record(?string $traceId = null, ?string $spanId = null): LogRecord
    {
        return new LogRecord(
            timestamp: new \DateTimeImmutable(),
            severity: new Severity('INFO', 9),
            message: 'msg',
            channel: null,
            resource: new ResourceAttributes(),
            attributes: new LogAttributes(),
            traceId: $traceId,
            spanId: $spanId,
        );
    }

    public function testSetsTraceAndSpanIdFromActiveSpan(): void
    {
        $scope = Span::wrap(SpanContext::create(self::TRACE_ID, self::SPAN_ID))->activate();
        try {
            $enriched = (new TraceContextEnricher())->enrich($this->record());
        } finally {
            $scope->detach();
        }

        self::assertSame(self::TRACE_ID, $enriched->traceId);
        self::assertSame(self::SPAN_ID, $enriched->spanId);
    }

    public function testLeavesRecordUnchangedWithoutActiveSpan(): void
    {
        $record = $this->record();

        self::assertSame($record, (new TraceContextEnricher())->enrich($record));
    }

    public function testKeepsExistingTraceId(): void
    {
        $record = $this->record('aaaa', 'bbbb');
        $scope = Span::wrap(SpanContext::create(self::TRACE_ID, self::SPAN_ID))->activate();
        try {
            $enriched = (new TraceContextEnricher())->enrich($record);
        } finally {
            $scope->detach();
        }

        self::assertSame($record, $enriched);
    }
}
