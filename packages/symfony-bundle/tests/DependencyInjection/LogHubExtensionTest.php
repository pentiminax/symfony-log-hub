<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Tests\DependencyInjection;

use LogHub\SymfonyBundle\DependencyInjection\LogHubExtension;
use LogHub\SymfonyBundle\Handler\LogHubHandler;
use LogHub\SymfonyBundle\Enricher\TraceContextEnricher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LogHubExtensionTest extends TestCase
{
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test']));
        // In a real Symfony app, framework-bundle registers these. Standing in for them here
        // since this test builds the container without framework-bundle.
        $container->register(RequestStack::class, RequestStack::class);
        $container->register(HttpClientInterface::class, NativeHttpClient::class);
        $container->registerExtension($extension = new LogHubExtension());
        $container->loadFromExtension($extension->getAlias(), array_merge([
            'url' => 'https://loghub.example',
            'api_key' => 'lh_secret',
            'service' => ['name' => 'billing-api'],
        ], $config));
        $container->compile();

        return $container;
    }

    public function testAliasIsLoghub(): void
    {
        self::assertSame('loghub', (new LogHubExtension())->getAlias());
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

        self::assertSame('lh_secret', $container->getParameter('loghub.api_key'));
        self::assertSame('billing-api', $container->getParameter('loghub.service_name'));
        self::assertSame('1.4.2', $container->getParameter('loghub.service_version'));
        self::assertSame(50, $container->getParameter('loghub.batch_max_records'));
        self::assertSame(2000, $container->getParameter('loghub.batch_max_bytes'));
    }

    public function testUrlIsMappedAndMasterKeyIsOptional(): void
    {
        $container = $this->buildContainer();

        self::assertSame('https://loghub.example', $container->getParameter('loghub.url'));
        self::assertNull($container->getParameter('loghub.master_key'));
        self::assertTrue($container->hasParameter('loghub.master_key'));
    }

    public function testApiKeyIsOptional(): void
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test']));
        (new LogHubExtension())->load([['url' => 'https://loghub.example', 'service' => ['name' => 'x']]], $container);

        self::assertNull($container->getParameter('loghub.api_key'));
    }

    public function testClientHttpClientCarriesTheMasterKey(): void
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test']));
        (new LogHubExtension())->load([[
            'url' => 'http://engine:8080',
            'master_key' => 'secret',
            'service' => ['name' => 'x'],
        ]], $container);

        self::assertSame(
            ['%loghub.url%', ['auth_bearer' => '%loghub.master_key%', 'timeout' => 5]],
            $container->getDefinition('loghub.http_client')->getArguments(),
        );
        self::assertSame('secret', $container->getParameter('loghub.master_key'));
    }

    public function testTraceContextEnricherIsRegisteredAndTagged(): void
    {
        $container = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test']));
        // Not compiled: compilation would inline/remove the enricher definition.
        (new LogHubExtension())->load([[
            'url' => 'https://loghub.example',
            'api_key' => 'lh_secret',
            'service' => ['name' => 'billing-api'],
        ]], $container);

        self::assertTrue($container->hasDefinition(TraceContextEnricher::class));
        self::assertArrayHasKey('loghub.enricher', $container->getDefinition(TraceContextEnricher::class)->getTags());
    }
}
