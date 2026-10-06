<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use Ramsey\Uuid\Uuid;
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
        private ClockInterface $clock,
    ) {
    }

    /**
     * The puzzles with a secret row (hide until the round starts) in these rounds - the only ones a change of the
     * rounds can re-sync. Read them after locking the rounds (lockRoundsForChange() does).
     *
     * @param array<string> $roundIds
     * @return list<string>
     */
    public function secretPuzzleIdsOfRounds(array $roundIds): array
    {
        $roundIds = array_values(array_filter($roundIds, Uuid::isValid(...)));

        if ($roundIds === []) {
            return [];
        }

        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT puzzle_id FROM competition_round_puzzle WHERE round_id IN (:roundIds) AND hide_until_round_starts = true ORDER BY puzzle_id',
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
        $hide = $this->hideOf($puzzle);

        // No row keeps it hidden any more: the dates stay as they are, never revealed by accident
        if ($hide === null) {
            return;
        }

        $puzzle->keepSecretUntil($hide['hiddenUntil'], $hide['imageHiddenUntil']);
    }

    /**
     * The site-wide hide the puzzle's rows ask for - null when no row keeps it hidden everywhere (the dates then stay).
     * The hypotheticals answer "what would this change reveal?" before it is made: rows left out (a removal, a deleted
     * round) and rounds at another start (a round edit).
     *
     * @param array<string> $withoutRoundPuzzleIds
     * @param array<string, DateTimeImmutable> $roundStartsAt round id => the start to assume
     * @return null|array{hiddenUntil: null|DateTimeImmutable, imageHiddenUntil: DateTimeImmutable}
     */
    public function hideOf(Puzzle $puzzle, array $withoutRoundPuzzleIds = [], array $roundStartsAt = []): null|array
    {
        $hiddenUntil = null;
        $imageHiddenUntil = null;
        $neverRevealed = new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED);
        $now = $this->clock->now();

        foreach ($this->rowsOf($puzzle) as $row) {
            if (
                $row->hidesEverywhere === false
                || $row->hideUntilRoundStarts === false
                || in_array($row->id->toString(), $withoutRoundPuzzleIds, true)
            ) {
                continue;
            }

            // A round edit pins reveals that already happened (EditCompetitionRoundHandler) - only the others move
            $startsAt = $row->isHiddenAt($now) ? ($roundStartsAt[$row->round->id->toString()] ?? $row->round->startsAt) : $row->round->startsAt;
            $revealsAt = $row->revealMode->revealAt($startsAt, $row->revealAt) ?? $neverRevealed;

            if ($imageHiddenUntil === null || $revealsAt > $imageHiddenUntil) {
                $imageHiddenUntil = $revealsAt;
            }

            if (($row->hideMode ?? PuzzleHideMode::Entirely) === PuzzleHideMode::Entirely && ($hiddenUntil === null || $revealsAt > $hiddenUntil)) {
                $hiddenUntil = $revealsAt;
            }
        }

        if ($imageHiddenUntil === null) {
            return null;
        }

        return ['hiddenUntil' => $hiddenUntil, 'imageHiddenUntil' => $imageHiddenUntil];
    }

    /**
     * Concurrency: every handler changing a secret row or a round's start first locks what its re-sync depends on, and
     * only then reads anything - so the read-compute-write of resync() never works on a state another transaction is
     * changing. Always in the same order, so two handlers never deadlock: the rounds first (ordered by id), then the
     * puzzles (ordered by id). The locks are held to the commit (doctrine_transaction).
     *
     * - A round's start or its list of puzzles changes (edit, delete, set puzzles): its row FOR NO KEY UPDATE.
     * - A row is added to a round or changed (add, reveal change, reveal now, keep hidden everywhere, remove): the
     *   round FOR SHARE - its start must not move meanwhile; several of them may run side by side.
     * - The puzzles: FOR NO KEY UPDATE, never FOR UPDATE - that one would also wait for every insert referencing the
     *   puzzle (a time, a collection item - FOR KEY SHARE). Only puzzles with a secret row, or getting one.
     *
     * Call it FIRST in the handler, before anything is loaded or changed: it clears the entity manager after locking,
     * so every row read afterwards is the committed one, never a copy loaded before the lock (the caller's own
     * entities are detached - read them again after the dispatch).
     *
     * Edit or delete of rounds: the rounds, then their secret puzzles (read after the rounds are locked, so no secret
     * row can be added to them meanwhile). Returns those puzzles - re-sync them after a delete by plain SQL.
     *
     * @param array<string> $roundIds
     * @return list<string>
     */
    public function lockRoundsForChange(array $roundIds): array
    {
        $this->lockRows('competition_round', $roundIds, 'FOR NO KEY UPDATE');
        $puzzleIds = $this->secretPuzzleIdsOfRounds($roundIds);
        $this->lockRows('puzzle', $puzzleIds, 'FOR NO KEY UPDATE');
        $this->entityManager->clear();

        return $puzzleIds;
    }

    /**
     * A row added to a round (see lockRoundsForChange()): the round (shared), then the puzzles - those getting a
     * secret row, or already having one.
     *
     * @param array<string> $puzzleIds
     */
    public function lockForAddingTo(string $roundId, array $puzzleIds, bool $addsSecretRow): void
    {
        $this->lockRows('competition_round', [$roundId], 'FOR SHARE');
        $this->lockRows('puzzle', $addsSecretRow ? $puzzleIds : $this->withSecretRows($puzzleIds), 'FOR NO KEY UPDATE');
        $this->entityManager->clear();
    }

    /**
     * One row changed or removed (see lockRoundsForChange()): its round (shared), then its puzzle.
     */
    public function lockRoundPuzzle(string $roundPuzzleId): void
    {
        if (Uuid::isValid($roundPuzzleId) === false) {
            return;
        }

        /** @var false|array{round_id: string, puzzle_id: string} $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT round_id, puzzle_id FROM competition_round_puzzle WHERE id = :id',
            ['id' => $roundPuzzleId],
        );

        if ($row === false) {
            return;
        }

        $this->lockRows('competition_round', [$row['round_id']], 'FOR SHARE');
        $this->lockRows('puzzle', [$row['puzzle_id']], 'FOR NO KEY UPDATE');
        $this->entityManager->clear();
    }

    /**
     * @param array<string> $puzzleIds
     * @return list<string>
     */
    private function withSecretRows(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_filter($puzzleIds, Uuid::isValid(...)));

        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<string> $withSecretRows */
        $withSecretRows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT puzzle_id FROM competition_round_puzzle WHERE puzzle_id IN (:ids) AND hide_until_round_starts = true',
            ['ids' => $puzzleIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return $withSecretRows;
    }

    /**
     * @param 'competition_round'|'puzzle' $table
     * @param 'FOR NO KEY UPDATE'|'FOR SHARE' $mode
     * @param array<string> $ids
     */
    private function lockRows(string $table, array $ids, string $mode): void
    {
        $ids = array_values(array_unique(array_map(strtolower(...), array_filter($ids, Uuid::isValid(...)))));

        if ($ids === []) {
            return;
        }

        $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT id FROM {$table} WHERE id IN (:ids) ORDER BY id {$mode}",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
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
