<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Transport;

interface BatchTransportInterface
{
    /** @param list<\LogHub\MonologBundle\Model\LogRecord> $records */
    public function send(array $records): void;
}
