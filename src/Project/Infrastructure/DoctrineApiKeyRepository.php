<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Log\Domain\ProjectId;
use App\Project\Application\ApiKeyRepositoryInterface;
use App\Project\Domain\ApiKey;
use App\Project\Domain\ApiKeyId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DoctrineApiKeyRepository implements ApiKeyRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(env: 'APP_SECRET')] private string $pepper,
    ) {
    }

    public function save(ApiKey $apiKey): void
    {
        $entity = $this->entityManager->find(ApiKeyEntity::class, (string) $apiKey->id) ?? ApiKeyEntity::fromDomain($apiKey);

        $entity->hashedKey = $apiKey->hashedKey;
        $entity->label = $apiKey->label;
        $entity->revokedAt = $apiKey->revokedAt;

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findById(ApiKeyId $id): ?ApiKey
    {
        return $this->entityManager->find(ApiKeyEntity::class, (string) $id)?->toDomain();
    }

    public function findActiveByProjectId(ProjectId $projectId): ?ApiKey
    {
        return $this->findOneActiveBy('a.projectId = :value', (string) $projectId);
    }

    public function resolveActiveByPlaintext(string $plaintext): ?ApiKey
    {
        return $this->findOneActiveBy('a.hashedKey = :value', ApiKey::hash($plaintext, $this->pepper));
    }

    private function findOneActiveBy(string $condition, string $value): ?ApiKey
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(ApiKeyEntity::class, 'a')
            ->where($condition)
            ->andWhere('a.revokedAt IS NULL')
            ->setParameter('value', $value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity?->toDomain();
    }
}
