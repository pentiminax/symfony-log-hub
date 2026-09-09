<?php

declare(strict_types=1);

namespace LogHub\MonologBundle\Tests\DependencyInjection;

use LogHub\MonologBundle\DependencyInjection\LogHubMonologExtension;
use LogHub\MonologBundle\Handler\LogHubHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LogHubMonologExtensionTest extends TestCase
{
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test']));
        // In a real Symfony app, framework-bundle registers these. Standing in for them here
        // since this test builds the container without framework-bundle.
        $container->register(RequestStack::class, RequestStack::class);
        $container->register(HttpClientInterface::class, NativeHttpClient::class);
        $container->registerExtension($extension = new LogHubMonologExtension());
        $container->loadFromExtension($extension->getAlias(), array_merge([
            'endpoint' => 'https://loghub.example/api/v1/logs',
            'api_key' => 'lh_secret',
            'service' => ['name' => 'billing-api'],
        ], $config));
        $container->compile();

        return $container;
    }

    public function testAliasIsLoghub(): void
    {
        self::assertSame('loghub', (new LogHubMonologExtension())->getAlias());
    }

    public function testContainerCompilesWithExpectedServices(): void
    {
        // Inner pipeline services (mapper, redactor, enricher, transport, buffer) are wired
        // privately with a single consumer each, so the compiler inlines and removes their
        // definitions — has() on those ids would fail post-compile even though wiring is
        // correct. The real acceptance criterion is the public surface: the handler (and its
        // spec 3.1 service id alias) must resolve to a working instance.
        $container = $this->buildContainer();

        self::assertTrue($container->has(LogHubHandler::class));
        self::assertInstanceOf(LogHubHandler::class, $container->get(LogHubHandler::class));
        self::assertTrue($container->has('loghub.monolog_handler'));
        self::assertInstanceOf(LogHubHandler::class, $container->get('loghub.monolog_handler'));
    }

    public function testParametersAreMappedFromConfig(): void
    {
        $container = $this->buildContainer([
            'service' => ['name' => 'billing-api', 'version' => '1.4.2'],
            'batch' => ['max_records' => 50, 'max_bytes' => 2000],
        ]);

        self::assertSame('https://loghub.example/api/v1/logs', $container->getParameter('loghub.endpoint'));
        self::assertSame('lh_secret', $container->getParameter('loghub.api_key'));
        self::assertSame('billing-api', $container->getParameter('loghub.service_name'));
        self::assertSame('1.4.2', $container->getParameter('loghub.service_version'));
        self::assertSame(50, $container->getParameter('loghub.batch_max_records'));
        self::assertSame(2000, $container->getParameter('loghub.batch_max_bytes'));
    }
}
