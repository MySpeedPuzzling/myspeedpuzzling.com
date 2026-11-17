<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use LogicException;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * A relay team's result (docs/features/event-results-import/solo-and-relays.md §2): one team solved two puzzles at
 * the same time and only the total is known. The team has one result per puzzle (puzzle_solving_time.relay_result_id),
 * both without a time of their own - this row holds the total the event ranks the team by. Written by the results
 * import only; removed after its results (the import deletes the results first, then this row).
 */
#[Entity]
class RelayResult
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // The team's total time of both puzzles
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $seconds,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        if ($seconds < 1) {
            throw new LogicException('A relay result has a total time.');
        }
    }

    /**
     * The organizer corrected the team's total on the sheet.
     *
     * @return bool whether it changed
     */
    public function correctTotal(int $seconds): bool
    {
        if ($seconds < 1) {
            throw new LogicException('A relay result has a total time.');
        }

        if ($this->seconds === $seconds) {
            return false;
        }

        $this->seconds = $seconds;

        return true;
    }

    /**
     * Each puzzle's share of the total - split evenly, the remainder on the first puzzle - so "time spent puzzling"
     * counts the total once (PuzzleSolvingTime::$secondsSpent of the relay's results).
     *
     * @return list<int> in the order of the puzzles
     */
    public static function shares(int $seconds, int $puzzles): array
    {
        if ($puzzles < 1) {
            throw new LogicException('A relay has puzzles.');
        }

        $share = intdiv($seconds, $puzzles);
        $shares = array_fill(0, $puzzles, $share);
        $shares[0] += $seconds - $share * $puzzles;

        return $shares;
    }
}
