<?php

declare(strict_types=1);

namespace LogHub\SymfonyBundle\Enricher;

use LogHub\SymfonyBundle\Model\LogRecord;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RequestAttributesEnricher implements LogEnricherInterface
{
    private const string REQUEST_ID_ATTRIBUTE = '_loghub_request_id';

    public function __construct(
        private RequestStack $requestStack,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function enrich(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return $record;
        }

        $attributes = $record->attributes
            ->with('http.request.method', $request->getMethod())
            ->with('url.path', $request->getPathInfo())
            ->with('client.address', $request->getClientIp())
            ->with('request.id', $this->resolveRequestId($request));

        $userId = $this->resolveUserId();
        if (null !== $userId) {
            $attributes = $attributes->with('user.id', $userId);
        }

        return $record->withAttributes($attributes);
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        if (\is_string($requestId)) {
            return $requestId;
        }

        $requestId = Uuid::v4()->toRfc4122();
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);

        return $requestId;
    }

    private function resolveUserId(): ?string
    {
        $token = $this->tokenStorage?->getToken();

        return $token?->getUser()?->getUserIdentifier();
    }
}
