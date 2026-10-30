<?php

declare(strict_types=1);

namespace LogHub\EngineBundle\Exception;

/**
 * Transport failure, or the engine answered 5xx (including 503).
 */
final class EngineUnavailableException extends EngineException
{
}
