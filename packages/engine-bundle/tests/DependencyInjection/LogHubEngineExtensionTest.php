<?php

declare(strict_types=1);

namespace LogHub\EngineBundle\Tests\DependencyInjection;

use LogHub\EngineBundle\Client\EngineClient;
use LogHub\EngineBundle\DependencyInjection\LogHubEngineExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class LogHubEngineExtensionTest extends TestCase
{
    public function testItRegistersTheClientOnAScopedHttpClientCarryingTheMasterKey(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($extension = new LogHubEngineExtension());
        $container->loadFromExtension('loghub_engine', ['url' => 'http://engine:8081', 'master_key' => 'secret']);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
        $container->compile();

        self::assertSame('loghub_engine', $extension->getAlias());
        self::assertTrue($container->hasDefinition(EngineClient::class));
        $args = $container->getDefinition('loghub_engine.http_client')->getArguments();
        self::assertSame(['http://engine:8081', ['auth_bearer' => 'secret', 'timeout' => 5]], $args);
    }
}
