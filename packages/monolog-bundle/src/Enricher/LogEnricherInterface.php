<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Enricher;

use LogHub\MonologBundle\Model\LogRecord;

interface LogEnricherInterface
{
    public function enrich(LogRecord $record): LogRecord;
}
