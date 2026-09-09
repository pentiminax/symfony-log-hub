<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = self::getContainer();
        $container->get(Connection::class)->executeStatement('TRUNCATE TABLE app_user CASCADE');

        $user = new User('ops@example.com');
        $user->password = $container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD);

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
    }

    public function testAnAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testValidCredentialsOpenTheApplication(): void
    {
        $this->login(self::PASSWORD);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('ops@example.com', $this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier());
    }

    public function testAWrongPasswordComesBackToTheFormWithAMessage(): void
    {
        $this->login('not-the-password');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[role="alert"]')->count());
    }

    /**
     * The CSRF token is not decoration here: form_login ignores a login attempt
     * without it, so a missing token must not silently authenticate anyone.
     */
    public function testALoginWithoutACsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/login', [
            '_username' => 'ops@example.com',
            '_password' => self::PASSWORD,
        ]);

        $this->client->followRedirect();
        self::assertNull($this->client->getContainer()->get('security.token_storage')->getToken());
    }

    public function testTheVisitorReturnsToThePageTheyAskedFor(): void
    {
        $this->client->request('GET', '/?q=timeout');
        $this->client->followRedirect();

        $this->login(self::PASSWORD);

        self::assertResponseRedirects('http://localhost/?q=timeout');
    }

    public function testSigningOutClearsTheSession(): void
    {
        $this->login(self::PASSWORD);
        $crawler = $this->client->followRedirect();

        // Through the rendered link, not a bare /logout: the route carries a CSRF
        // token, and hand-building the URL would test a path nobody takes.
        $this->client->click($crawler->filter('a[href^="/logout"]')->link());
        $this->client->followRedirect();

        self::assertNull($this->client->getContainer()->get('security.token_storage')->getToken());
    }

    public function testTheFirstRunHintNamesTheAccountCommand(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE app_user CASCADE');

        $crawler = $this->client->request('GET', '/login');

        self::assertStringContainsString('app:user:create', $crawler->text());
    }

    private function login(string $password): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'ops@example.com',
            '_password' => $password,
        ]));
    }
}
