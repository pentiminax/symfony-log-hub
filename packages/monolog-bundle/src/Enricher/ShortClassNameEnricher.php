<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Enricher;

use LogHub\MonologBundle\Model\LogRecord;

final readonly class ShortClassNameEnricher implements LogEnricherInterface
{
    private const string FQCN_PATTERN = '/(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*/';

    public function __construct(
        private bool $enabled,
    ) {
    }

    public function enrich(LogRecord $record): LogRecord
    {
        if (!$this->enabled) {
            return $record;
        }

        $message = preg_replace_callback(
            self::FQCN_PATTERN,
            static fn (array $match): string => substr($match[0], strrpos($match[0], '\\') + 1),
            $record->message,
        );

        return $record->withMessage($message ?? $record->message);
    }
}
