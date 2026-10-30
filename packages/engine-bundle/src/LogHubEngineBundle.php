<?php

declare(strict_types=1);

namespace LogHub\EngineBundle;

use LogHub\EngineBundle\DependencyInjection\LogHubEngineExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class LogHubEngineBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        // Alias "loghub_engine" differs from the naming-convention default ("log_hub_engine").
        return new LogHubEngineExtension();
    }
}
