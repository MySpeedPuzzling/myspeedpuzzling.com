<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2ClientRequest;

final readonly class OAuth2ClientRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $id): OAuth2ClientRequest
    {
        $request = $this->entityManager->find(OAuth2ClientRequest::class, $id);
        assert($request !== null);

        return $request;
    }

    public function findByClaimTokenHash(string $tokenHash): null|OAuth2ClientRequest
    {
        /** @var null|OAuth2ClientRequest $result */
        $result = $this->entityManager
            ->createQueryBuilder()
            ->select('r')
            ->from(OAuth2ClientRequest::class, 'r')
            ->where('r.credentialClaimToken = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function save(OAuth2ClientRequest $request): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->flush();
    }
}
