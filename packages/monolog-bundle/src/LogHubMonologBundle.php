<?php

declare(strict_types=1);

namespace LogHub\MonologBundle;

use LogHub\MonologBundle\DependencyInjection\LogHubMonologExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class LogHubMonologBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        // The default convention would expect alias "log_hub_monolog"; the config key is
        // "loghub" per spec 3.1, so the naming-convention check is bypassed explicitly here.
        return new LogHubMonologExtension();
    }
}
