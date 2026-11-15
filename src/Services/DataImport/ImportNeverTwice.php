<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * "Never twice" against what the player already has (docs/features/data-import/adding.md "Never twice", README
 * "Never twice" steps 1, 2 and 4) - asked for one row at a time, under the player's import lock (ImportWriteLock), so
 * what it reads cannot change before the row is written.
 *
 * **What the player has** = their results of the puzzle as they are now: tracked by them, or of a pair/team they are a
 * registered member of. A result that was deleted or undone is not there. **A result pairs off with one row only**: one
 * that a row of any of the player's imports made, or was settled as "already yours" with, is spoken for.
 *
 * The day of a result is its solve day, else the day it was logged. People are not compared: a guest "Ben" and the
 * registered Ben are the same solve.
 *
 * DQL only - nothing here names the results' table (the suspicious-time and trackerless-time guards list raw SQL):
 * a result waiting for verification is the player's like any other, and a result without a tracker is found through
 * its pair/team.
 */
readonly final class ImportNeverTwice
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DataImportRowRepository $rowRepository,
    ) {
    }

    /**
     * The player's results of the puzzle no row has spoken for yet.
     *
     * @return list<array{id: string, day: string, seconds: null|int, type: PuzzlingType}>
     */
    public function freeResultsOnProfile(Player $player, Puzzle $puzzle): array
    {
        $results = $this->resultsOnProfile($player, $puzzle);

        if ($results === []) {
            return [];
        }

        $spokenFor = $this->rowRepository->solvingTimeIdsSpokenFor(
            $player->id->toString(),
            array_map(static fn (array $result): string => $result['id'], $results),
        );

        return array_values(array_filter($results, static fn (array $result): bool => isset($spokenFor[$result['id']]) === false));
    }

    /**
     * Whether the player has any result of the puzzle - what keeps a puzzle off the wish list.
     */
    public function hasSolved(Player $player, Puzzle $puzzle): bool
    {
        return $this->resultsOnProfile($player, $puzzle) !== [];
    }

    /**
     * The free result that is this row: the same day, time and solo/pair/team. The one the pairing's hint names comes
     * first.
     *
     * @param list<array{id: string, day: string, seconds: null|int, type: PuzzlingType}> $free
     */
    public static function equal(array $free, string $day, null|int $seconds, PuzzlingType $type, null|string $hintedId): null|string
    {
        $found = null;

        foreach ($free as $result) {
            if ($result['day'] !== $day || $result['seconds'] !== $seconds || $result['type'] !== $type) {
                continue;
            }

            if ($result['id'] === $hintedId) {
                return $result['id'];
            }

            $found ??= $result['id'];
        }

        return $found;
    }

    /**
     * A free result that may be this row written differently: the same time on another day, or the same day with
     * another time (README step 4) - the player is asked, nothing is added by itself.
     *
     * @param list<array{id: string, day: string, seconds: null|int, type: PuzzlingType}> $free
     */
    public static function similar(array $free, string $day, null|int $seconds): null|string
    {
        foreach ($free as $result) {
            $sameDay = $result['day'] === $day;
            $sameTime = $seconds !== null && $result['seconds'] === $seconds;

            if ($sameDay !== $sameTime) {
                return $result['id'];
            }
        }

        return null;
    }

    /**
     * A pair's or team's row: whether somebody else - a result the player is not in - has a pair/team result of the
     * puzzle with this day and time. Two partners importing the same evening would otherwise leave two public
     * results of one solve.
     */
    public function somebodyElseHasIt(Player $player, Puzzle $puzzle, string $day, int $seconds): bool
    {
        /** @var list<array{id: \Ramsey\Uuid\UuidInterface, finishedAt: null|DateTimeImmutable, trackedAt: DateTimeImmutable}> $others */
        $others = $this->entityManager->createQueryBuilder()
            ->select('time.id', 'time.finishedAt', 'time.trackedAt')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.puzzle = :puzzle')
            ->andWhere('time.secondsToSolve = :seconds')
            ->andWhere('time.puzzlingType != :solo')
            ->andWhere('time.player IS NULL OR time.player != :player')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . PuzzlingTeamMember::class . ' teamMember WHERE teamMember.team = time.puzzlingTeam AND teamMember.player = :player)')
            ->setParameter('puzzle', $puzzle)
            ->setParameter('seconds', $seconds)
            ->setParameter('solo', PuzzlingType::Solo)
            ->setParameter('player', $player)
            ->setMaxResults(50)
            ->getQuery()
            ->getArrayResult();

        foreach ($others as $other) {
            if (($other['finishedAt'] ?? $other['trackedAt'])->format('Y-m-d') === $day) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, day: string, seconds: null|int, type: PuzzlingType}>
     */
    private function resultsOnProfile(Player $player, Puzzle $puzzle): array
    {
        /** @var list<array{id: \Ramsey\Uuid\UuidInterface, secondsToSolve: null|int, finishedAt: null|DateTimeImmutable, trackedAt: DateTimeImmutable, puzzlingType: PuzzlingType}> $results */
        $results = $this->entityManager->createQueryBuilder()
            ->select('time.id', 'time.secondsToSolve', 'time.finishedAt', 'time.trackedAt', 'time.puzzlingType')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.puzzle = :puzzle')
            ->andWhere('time.player = :player OR EXISTS (SELECT 1 FROM ' . PuzzlingTeamMember::class . ' teamMember WHERE teamMember.team = time.puzzlingTeam AND teamMember.player = :player)')
            ->setParameter('puzzle', $puzzle)
            ->setParameter('player', $player)
            ->orderBy('time.id')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $result): array => [
            'id' => $result['id']->toString(),
            'day' => ($result['finishedAt'] ?? $result['trackedAt'])->format('Y-m-d'),
            'seconds' => $result['secondsToSolve'],
            'type' => $result['puzzlingType'],
        ], $results);
    }
}
