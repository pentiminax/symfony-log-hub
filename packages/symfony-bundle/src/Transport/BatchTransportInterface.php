<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Transport;

interface BatchTransportInterface
{
    /** @param list<\LogHub\SymfonyBundle\Model\LogRecord> $records */
    public function send(array $records): void;
}
