<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle;

use LogHub\SymfonyBundle\DependencyInjection\LogHubExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class LogHubBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        // The naming-convention default would be alias "log_hub"; the config key is "loghub".
        return new LogHubExtension();
    }
}
