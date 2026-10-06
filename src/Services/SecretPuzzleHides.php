<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Value\PuzzleHideMode;

/**
 * The site-wide hide of a secret competition puzzle belongs to the PUZZLE, not to one round: a puzzle may be secret in
 * several rounds. Its hide dates are the LATEST reveal among the round puzzles that keep it hidden everywhere
 * (CompetitionRoundPuzzle::$hidesEverywhere, still secret): puzzle.hide_image_until = the latest of all of them,
 * puzzle.hide_until = the latest of those hiding it entirely ("entirely" beats "image only"). A manual reveal not made
 * yet counts as never (CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED).
 *
 * Call resync() after anything that may move a reveal: adding a puzzle to a round, a reveal change, "Reveal now",
 * a round's start, removing the puzzle from a round, deleting a round or an event, a merge. With no such row left the
 * dates stay as they are - a puzzle is never revealed by accident.
 *
 * Works on the unit of work as it is now (rows added or removed in this handler count), since a handler only flushes
 * at its end (doctrine_transaction).
 */
readonly final class SecretPuzzleHides
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * For handlers deleting rounds with plain SQL: the puzzles of the rows about to go, by id. Re-sync them afterwards.
     *
     * @param array<string> $roundIds
     * @return list<string>
     */
    public function puzzleIdsOfRounds(array $roundIds): array
    {
        if ($roundIds === []) {
            return [];
        }

        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT puzzle_id FROM competition_round_puzzle WHERE round_id IN (:roundIds)',
            ['roundIds' => $roundIds],
            ['roundIds' => ArrayParameterType::STRING],
        );

        return $puzzleIds;
    }

    /**
     * @param array<string> $puzzleIds
     */
    public function resyncByIds(array $puzzleIds): void
    {
        foreach ($puzzleIds as $puzzleId) {
            $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);

            if ($puzzle !== null) {
                $this->resyncOne($puzzle);
            }
        }
    }

    public function resync(Puzzle ...$puzzles): void
    {
        foreach ($puzzles as $puzzle) {
            $this->resyncOne($puzzle);
        }
    }

    /**
     * Every row of the puzzle as this handler sees it: the stored ones, minus those removed or moved away, plus those
     * added or moved here in this unit of work.
     *
     * Rows deleted by plain SQL earlier in the handler (deleting a round or an event) are gone too.
     *
     * @return list<CompetitionRoundPuzzle>
     */
    public function rowsOf(Puzzle $puzzle): array
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();

        /** @var array<CompetitionRoundPuzzle> $candidates */
        $candidates = $this->entityManager->getRepository(CompetitionRoundPuzzle::class)->findBy(['puzzle' => $puzzle]);

        foreach ($unitOfWork->getIdentityMap()[CompetitionRoundPuzzle::class] ?? [] as $managed) {
            if ($managed instanceof CompetitionRoundPuzzle && $managed->puzzle === $puzzle) {
                $candidates[] = $managed;
            }
        }

        foreach ($unitOfWork->getScheduledEntityInsertions() as $inserted) {
            if ($inserted instanceof CompetitionRoundPuzzle && $inserted->puzzle === $puzzle) {
                $candidates[] = $inserted;
            }
        }

        $rows = [];

        foreach ($candidates as $row) {
            $key = spl_object_id($row);

            if (
                isset($rows[$key])
                || $row->puzzle !== $puzzle
                || $unitOfWork->isScheduledForDelete($row)
            ) {
                continue;
            }

            // A managed row that is neither stored for this puzzle nor new here was deleted by SQL - findBy no longer
            // returns it
            if ($unitOfWork->isScheduledForInsert($row) === false && $this->isStoredFor($row, $puzzle) === false) {
                continue;
            }

            $rows[$key] = $row;
        }

        return array_values($rows);
    }

    private function resyncOne(Puzzle $puzzle): void
    {
        $hiddenUntil = null;
        $imageHiddenUntil = null;
        $neverRevealed = new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED);

        foreach ($this->rowsOf($puzzle) as $row) {
            if ($row->hidesEverywhere === false || $row->hideUntilRoundStarts === false) {
                continue;
            }

            $revealsAt = $row->revealsAt() ?? $neverRevealed;

            if ($imageHiddenUntil === null || $revealsAt > $imageHiddenUntil) {
                $imageHiddenUntil = $revealsAt;
            }

            if (($row->hideMode ?? PuzzleHideMode::Entirely) === PuzzleHideMode::Entirely && ($hiddenUntil === null || $revealsAt > $hiddenUntil)) {
                $hiddenUntil = $revealsAt;
            }
        }

        // No row keeps it hidden any more: the dates stay as they are, never revealed by accident
        if ($imageHiddenUntil === null) {
            return;
        }

        $puzzle->keepSecretUntil($hiddenUntil, $imageHiddenUntil);
    }

    private function isStoredFor(CompetitionRoundPuzzle $row, Puzzle $puzzle): bool
    {
        $stored = $this->entityManager->getUnitOfWork()->getOriginalEntityData($row);

        // Moved here by a merge in this handler (moveToPuzzle) - the stored puzzle is another one
        if (($stored['puzzle'] ?? null) !== $puzzle) {
            return true;
        }

        return $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM competition_round_puzzle WHERE id = :id',
            ['id' => $row->id->toString()],
        ) !== false;
    }
}
