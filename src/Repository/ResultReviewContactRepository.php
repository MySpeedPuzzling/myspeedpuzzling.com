<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultReviewContact;
use SpeedPuzzling\Web\Value\ResultReviewContactStatus;

readonly final class ResultReviewContactRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ResultReviewContact $contact): void
    {
        $this->entityManager->persist($contact);
    }

    public function find(string $contactId): null|ResultReviewContact
    {
        if (!Uuid::isValid($contactId)) {
            return null;
        }

        return $this->entityManager->find(ResultReviewContact::class, $contactId);
    }

    /**
     * The e-mail the player got last - the one a reaction is credited to.
     */
    public function findLatestSentOf(string $playerId): null|ResultReviewContact
    {
        if (!Uuid::isValid($playerId)) {
            return null;
        }

        /** @var null|ResultReviewContact $contact */
        $contact = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(ResultReviewContact::class, 'c')
            ->where('c.player = :playerId')
            ->andWhere('c.status = :sent')
            ->setParameter('playerId', $playerId)
            ->setParameter('sent', ResultReviewContactStatus::Sent)
            ->orderBy('c.sentAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $contact;
    }
}
