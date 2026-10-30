<?php

declare(strict_types=1);

namespace App\Tests\UI\Controller;

use App\Security\User;
use App\Tests\Fake\InMemoryProjectRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LogsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE app_user CASCADE');

        $user = new User('ops@example.com');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $this->client->loginUser($user);
    }

    /** The root path *is* Discover; there is no landing page in front of it. */
    public function testTheRootPathRendersTheExplorer(): void
    {
        self::getContainer()->get(InMemoryProjectRepository::class)->create('Checkout');

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('Logs', $crawler->filter('h1')->text());
        self::assertCount(1, $crawler->filter('table'));
    }

    /** A fresh install has no project: say so instead of rendering an empty table. */
    public function testAFreshInstallExplainsThatThereIsNoProjectYet(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No project yet', $crawler->text());
        self::assertCount(0, $crawler->filter('table'));
    }

    /** A crafted filter must reach the store and come back as a banner, not a 500. */
    public function testAnUnknownFilterFieldInTheUrlIsReportedNotFatal(): void
    {
        self::getContainer()->get(InMemoryProjectRepository::class)->create('Checkout');

        $crawler = $this->client->request('GET', '/?f%5B0%5D%5Bfield%5D=nope.nope&f%5B0%5D%5Bop%5D=equals&f%5B0%5D%5Bvalue%5D=x');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[role="alert"]')->count());
    }
}
