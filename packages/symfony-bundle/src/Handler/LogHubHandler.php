<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Handler;

use LogHub\SymfonyBundle\Buffer\LogBuffer;
use LogHub\SymfonyBundle\Enricher\LogEnricherInterface;
use LogHub\SymfonyBundle\Mapper\MonologRecordMapper;
use LogHub\SymfonyBundle\Redaction\AttributeRedactor;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord as MonologLogRecord;

final class LogHubHandler extends AbstractProcessingHandler
{
    /** @param iterable<LogEnricherInterface> $enrichers */
    public function __construct(
        private readonly MonologRecordMapper $mapper,
        private readonly iterable $enrichers,
        private readonly AttributeRedactor $redactor,
        private readonly LogBuffer $buffer,
        int|string|Level $level = Level::Info,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        register_shutdown_function($this->flush(...));
    }

    protected function write(MonologLogRecord $record): void
    {
        // Doctrine logs this on every connection open; it's pure noise (never a query),
        // unlike "Executing statement" on the same channel, which is worth shipping.
        if ('doctrine' === $record->channel && str_starts_with($record->message, 'Connecting with parameters')) {
            return;
        }

        try {
            $mapped = $this->mapper->map($record);

            foreach ($this->enrichers as $enricher) {
                $mapped = $enricher->enrich($mapped);
            }

            $mapped = $this->redactor->redact($mapped);

            $this->buffer->add($mapped);
        } catch (\Throwable) {
            // Fail-open (spec 3.7/8.5): a broken pipeline stage must never break application logging,
            // and must never recursively log to LogHub itself.
        }
    }

    public function flush(): void
    {
        $this->buffer->flush();
    }
}
