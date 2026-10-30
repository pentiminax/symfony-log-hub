<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Enricher;

use LogHub\SymfonyBundle\Model\LogRecord;

interface LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord;
}
