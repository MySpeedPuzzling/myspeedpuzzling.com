<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\FinishedPhotoAnalysis;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

readonly final class FinishedPhotoAnalysisRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Every stored finished-photo analysis of the listed solving times, in one statement.
     *
     * @param list<PuzzleSolvingTime> $solvingTimes
     *
     * @return list<FinishedPhotoAnalysis>
     */
    public function findForSolvingTimes(array $solvingTimes): array
    {
        if ($solvingTimes === []) {
            return [];
        }

        /** @var list<FinishedPhotoAnalysis> $analyses */
        $analyses = $this->entityManager->createQueryBuilder()
            ->select('analysis')
            ->from(FinishedPhotoAnalysis::class, 'analysis')
            ->where('analysis.solvingTime IN (:times)')
            ->setParameter('times', $solvingTimes)
            ->getQuery()
            ->getResult();

        return $analyses;
    }

    public function save(FinishedPhotoAnalysis $analysis): void
    {
        $this->entityManager->persist($analysis);
    }

    /**
     * Deletes the analyses of photos a player removed or replaced, and of every photo of a player who went private -
     * what was measured from a photo must not outlive the photo being there to be measured. A bulk statement on
     * purpose (PruneFinishedPhotoAnalyses, a daily prune over every row): the rows are never loaded one by one.
     * A row of another catalogue picture stays - it reads as stale and the next run replaces it.
     *
     * @return int the deleted rows
     */
    public function deleteForGonePhotos(): int
    {
        $photo = PuzzleImageFingerprint::sql('pst.finished_puzzle_photo');

        return (int) $this->entityManager->getConnection()->executeStatement(
            <<<SQL
DELETE FROM finished_photo_analysis a
USING puzzle_solving_time pst, player pl
WHERE pst.id = a.solving_time_id
    AND pl.id = pst.player_id
    AND (pst.finished_puzzle_photo IS NULL OR {$photo} <> a.photo_fingerprint OR pl.is_private = true)
SQL,
        );
    }
}
