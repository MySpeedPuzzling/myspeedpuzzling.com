<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineMediaCheck;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;

readonly final class OnlineMediaCheckRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The photo's check by this worker version (OnlineMediaCheck::NO_VERSION: the row of a check that could not run)
     */
    public function ofMedia(string $mediaId, string $workerVersion): null|OnlineMediaCheck
    {
        if (!Uuid::isValid($mediaId)) {
            return null;
        }

        $check = $this->entityManager->getRepository(OnlineMediaCheck::class)->findOneBy([
            'media' => strtolower($mediaId),
            'workerVersion' => $workerVersion,
        ]);

        return $check instanceof OnlineMediaCheck ? $check : null;
    }

    /**
     * Has any worker version given the photo a verdict (complete, gaps or unsure - a failed check is none)?
     */
    public function hasVerdict(string $mediaId): bool
    {
        if (!Uuid::isValid($mediaId)) {
            return false;
        }

        $found = $this->entityManager->createQueryBuilder()
            ->select('mediaCheck.id')
            ->from(OnlineMediaCheck::class, 'mediaCheck')
            ->where('mediaCheck.media = :mediaId')
            ->andWhere('mediaCheck.verdict <> :failed')
            ->setParameter('mediaId', strtolower($mediaId))
            ->setParameter('failed', OnlineFinishCheckVerdict::Failed)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $found !== null;
    }

    /**
     * Has a check of a photo of the same round failed already (the warning is said once per round at most)?
     */
    public function roundHasFailure(OnlineEntryMedia $media): bool
    {
        $found = $this->entityManager->createQueryBuilder()
            ->select('mediaCheck.id')
            ->from(OnlineMediaCheck::class, 'mediaCheck')
            ->innerJoin('mediaCheck.media', 'media')
            ->innerJoin('media.onlineEntry', 'onlineEntry')
            ->innerJoin('onlineEntry.entry', 'entry')
            ->where('entry.round = :roundId')
            ->andWhere('mediaCheck.verdict = :failed')
            ->setParameter('roundId', $media->onlineEntry->entry->round->id->toString())
            ->setParameter('failed', OnlineFinishCheckVerdict::Failed)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $found !== null;
    }

    public function save(OnlineMediaCheck $check): void
    {
        $this->entityManager->persist($check);
    }
}
