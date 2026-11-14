<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SolvingTimeCustomValues;
use SpeedPuzzling\Web\Repository\SolvingTimeCustomValuesRepository;

/**
 * Copies of a result are removed and one stays ("Keep this one", the automatic removal of the same form sent twice -
 * docs/features/duplicate-results.md): what the players wrote into their own fields on a copy must not go with it
 * (docs/features/custom-fields.md "When a copy of a result is removed"). Called before the copies are deleted.
 *
 * Each player's row moves onto the kept result. Where the kept result has that player's row already, it wins: only
 * the fields it does not hold are filled from the copies, the first copy first. A player who is not on the kept
 * result has nothing there - their row goes with the copy.
 *
 * Rows move from one result of a player to another result of the same player - nothing is read out, nobody else's
 * row is touched.
 */
readonly final class CustomValuesOfRemovedCopy
{
    public function __construct(
        private SolvingTimeCustomValuesRepository $solvingTimeCustomValuesRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<PuzzleSolvingTime> $removedCopies in the order their values count: the first copy fills first
     */
    public function moveTo(array $removedCopies, PuzzleSolvingTime $kept): void
    {
        $membersOfKept = $kept->memberPlayerIds();
        $now = $this->clock->now();
        // The kept result's row per player - looked up once, and a row made here is found by the next copy
        /** @var array<string, null|SolvingTimeCustomValues> $keptRows */
        $keptRows = [];

        foreach ($removedCopies as $removedCopy) {
            foreach ($this->solvingTimeCustomValuesRepository->ofSolvingTime($removedCopy) as $row) {
                $playerId = $row->player->id->toString();

                if (in_array($playerId, $membersOfKept, true)) {
                    if (array_key_exists($playerId, $keptRows) === false) {
                        $keptRows[$playerId] = $this->solvingTimeCustomValuesRepository->find($kept, $row->player);
                    }

                    $keptRow = $keptRows[$playerId];

                    if ($keptRow === null) {
                        $keptRows[$playerId] = new SolvingTimeCustomValues(Uuid::uuid7(), $kept, $row->player, $row->values(), $now);
                        $this->solvingTimeCustomValuesRepository->save($keptRows[$playerId]);
                    } else {
                        // The copy's values first, the kept result's over them: what the kept one holds stays
                        $filled = $row->values()->with($keptRow->values()->values);

                        if ($filled->values !== $keptRow->values()->values) {
                            $keptRow->change($filled, $now);
                        }
                    }
                }

                // With the copy - removed here, not left to the foreign key: the unit of work holds the row
                $this->solvingTimeCustomValuesRepository->delete($row);
            }
        }
    }
}
