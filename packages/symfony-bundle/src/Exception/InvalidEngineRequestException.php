<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Exception;

/**
 * The engine rejected the request (400/422); the message is the engine's own.
 */
final class InvalidEngineRequestException extends EngineException
{
}
