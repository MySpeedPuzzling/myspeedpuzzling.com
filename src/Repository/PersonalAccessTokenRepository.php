<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PersonalAccessToken;

final readonly class PersonalAccessTokenRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findActiveByTokenHash(string $tokenHash): null|PersonalAccessToken
    {
        /** @var null|PersonalAccessToken $result */
        $result = $this->entityManager
            ->createQueryBuilder()
            ->select('t')
            ->from(PersonalAccessToken::class, 't')
            ->where('t.tokenHash = :tokenHash')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Revoked tokens included - their usage before the revocation still counts.
     *
     * @param list<string> $ids
     * @return list<PersonalAccessToken>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<PersonalAccessToken> $result */
        $result = $this->entityManager
            ->createQueryBuilder()
            ->select('t')
            ->from(PersonalAccessToken::class, 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function save(PersonalAccessToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }
}
