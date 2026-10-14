<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineMediaCheck;
use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use SpeedPuzzling\Web\Value\OnlineMediaKind;

readonly final class OnlineEntryMediaRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OnlineEntryMediaNotFound
     */
    public function get(string $mediaId): OnlineEntryMedia
    {
        if (!Uuid::isValid($mediaId)) {
            throw new OnlineEntryMediaNotFound();
        }

        $media = $this->entityManager->find(OnlineEntryMedia::class, $mediaId);

        return $media ?? throw new OnlineEntryMediaNotFound();
    }

    /**
     * The entry's latest object of a kind the server verified, taken at or before a moment (the empty mat a finish photo
     * is compared with - the completeness check, OnlineCvInputs)
     */
    public function latestVerified(string $onlineEntryId, OnlineMediaKind $kind, int $takenUntilMs): null|OnlineEntryMedia
    {
        if (!Uuid::isValid($onlineEntryId)) {
            return null;
        }

        $media = $this->entityManager->createQueryBuilder()
            ->select('media')
            ->from(OnlineEntryMedia::class, 'media')
            ->where('media.onlineEntry = :onlineEntryId')
            ->andWhere('media.kind = :kind')
            ->andWhere('media.verifiedBytes IS NOT NULL')
            ->andWhere('media.takenMs <= :takenUntilMs')
            ->setParameter('onlineEntryId', strtolower($onlineEntryId))
            ->setParameter('kind', $kind)
            ->setParameter('takenUntilMs', $takenUntilMs)
            ->orderBy('media.takenMs', 'DESC')
            ->addOrderBy('media.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $media instanceof OnlineEntryMedia ? $media : null;
    }

    /**
     * The verified finish photos (`candidate`, `button`, `end`) of a round, by the moment they were taken - those without
     * a verdict of the completeness check only (none yet, or only a failed check), unless every one is asked for
     *
     * @return list<string>
     */
    public function finishPhotoIdsOfRound(string $roundId, bool $withoutVerdictOnly): array
    {
        if (!Uuid::isValid($roundId)) {
            return [];
        }

        $query = $this->entityManager->createQueryBuilder()
            ->select('media.id')
            ->from(OnlineEntryMedia::class, 'media')
            ->innerJoin('media.onlineEntry', 'onlineEntry')
            ->innerJoin('onlineEntry.entry', 'entry')
            ->where('entry.round = :roundId')
            ->andWhere('media.kind IN (:kinds)')
            ->andWhere('media.verifiedBytes IS NOT NULL')
            ->setParameter('roundId', strtolower($roundId))
            ->setParameter('kinds', array_values(array_filter(OnlineMediaKind::cases(), static fn (OnlineMediaKind $kind): bool => $kind->isFinishPhoto())))
            ->orderBy('media.takenMs', 'ASC')
            ->addOrderBy('media.id', 'ASC');

        if ($withoutVerdictOnly) {
            $query->andWhere(sprintf(
                'NOT EXISTS (SELECT mediaCheck.id FROM %s mediaCheck WHERE mediaCheck.media = media AND mediaCheck.verdict <> :failed)',
                OnlineMediaCheck::class,
            ))->setParameter('failed', OnlineFinishCheckVerdict::Failed);
        }

        /** @var list<array{id: \Ramsey\Uuid\UuidInterface|string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_map(static fn (array $row): string => is_string($row['id']) ? $row['id'] : $row['id']->toString(), $rows);
    }

    public function save(OnlineEntryMedia $media): void
    {
        $this->entityManager->persist($media);
    }

    public function delete(OnlineEntryMedia $media): void
    {
        $this->entityManager->remove($media);
    }
}
