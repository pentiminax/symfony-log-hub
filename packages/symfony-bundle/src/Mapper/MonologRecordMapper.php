<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Mapper;

use LogHub\SymfonyBundle\Model\ExceptionData;
use LogHub\SymfonyBundle\Model\LogAttributes;
use LogHub\SymfonyBundle\Model\LogRecord;
use LogHub\SymfonyBundle\Model\ResourceAttributes;
use LogHub\SymfonyBundle\Model\Severity;
use Monolog\Level;
use Monolog\LogRecord as MonologLogRecord;

final class MonologRecordMapper
{
    /** @var array<int, int> Monolog\Level::value => OTel-style severity number (spec 3, example uses ERROR=17) */
    private const array LEVEL_TO_SEVERITY_NUMBER = [
        Level::Debug->value => 5,
        Level::Info->value => 9,
        Level::Notice->value => 10,
        Level::Warning->value => 13,
        Level::Error->value => 17,
        Level::Critical->value => 18,
        Level::Alert->value => 19,
        Level::Emergency->value => 21,
    ];

    public function map(MonologLogRecord $record): LogRecord
    {
        $context = $record->context;
        $exception = $this->extractException($context);
        unset($context['exception']);

        $attributes = new LogAttributes([
            ...$this->filterJsonCompatible($context),
            ...$this->filterJsonCompatible($record->extra),
        ]);

        return new LogRecord(
            timestamp: \DateTimeImmutable::createFromInterface($record->datetime)->setTimezone(new \DateTimeZone('UTC')),
            severity: new Severity($record->level->getName(), self::LEVEL_TO_SEVERITY_NUMBER[$record->level->value] ?? 0),
            message: $record->message,
            channel: $record->channel,
            resource: new ResourceAttributes(),
            attributes: $attributes,
            exception: $exception,
        );
    }

    /** @param array<string, mixed> $context */
    private function extractException(array $context): ?ExceptionData
    {
        $exception = $context['exception'] ?? null;

        if (!$exception instanceof \Throwable) {
            return null;
        }

        return new ExceptionData(
            type: $exception::class,
            message: $exception->getMessage(),
            stacktrace: $exception->getTraceAsString(),
            code: $exception->getCode(),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function filterJsonCompatible(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $result[$key] = $this->filterJsonCompatible($value);
            } elseif (null === $value || \is_scalar($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
