<?php

declare(strict_types=1);

namespace App\Security;

use App\Project\Application\ApiKeyRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Machine-to-machine auth for `/api/v1/*`: resolves the calling project purely from the
 * `Authorization: Bearer lh_<secret>` header, per spec 04.2 (no trusted `project_id` in
 * the request). Scoped to that firewall pattern, so supports() always attempts auth here.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeys,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException('Missing or malformed Authorization header.');
        }

        $plaintext = substr($header, \strlen('Bearer '));

        // resolveActiveByPlaintext already excludes non-matching and revoked keys (spec 8.2).
        $apiKey = $this->apiKeys->resolveActiveByPlaintext($plaintext);

        if (null === $apiKey) {
            throw new CustomUserMessageAuthenticationException('Invalid or revoked API key.');
        }

        return new SelfValidatingPassport(
            new UserBadge((string) $apiKey->projectId, static fn (): AuthenticatedProject => new AuthenticatedProject($apiKey->projectId)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'invalid_api_key'], Response::HTTP_UNAUTHORIZED);
    }
}
