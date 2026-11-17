<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * The per-puzzle record of one entry of a `pick` round (competition toolkit A3, A-round-formats.md §2.2,
 * R-review-and-plan.md §1.1): the round puzzles the entry chose, in solving order, each finished or not.
 *
 * Wire format (`puzzles` of the entry JSON and of a RecordRoundResults change; stored as is in `result_puzzles`):
 *
 *     [{"roundPuzzleId": "<competition_round_puzzle.id>", "done": true, "atSeconds": 2710},
 *      {"roundPuzzleId": "<…>", "done": false, "piecesPlaced": 312}]
 *
 * null = nothing recorded (an empty list reads as null). `atSeconds` only on a done item, `piecesPlaced` only on one
 * not done; any other key or shape is unreadable. The rules (refusal reasons are official_results.reason.*):
 *
 * 1. only `pick` rounds take it (`puzzles_not_used` - the handler's check);
 * 2. each item is a puzzle of the round (`unknown_round_puzzle`), listed once (`puzzle_listed_twice`);
 * 3. at most K items (`too_many_puzzles`);
 * 4. `atSeconds` 1..RoundEntryResult::MAX_SECONDS (`invalid_split`); a not-done item never before a done one, given
 *    splits strictly increase (`splits_out_of_order`);
 * 5. `piecesPlaced` 1..that puzzle's pieces − 1 (`invalid_pieces_placed`);
 * 6. with the result (`puzzles_mismatch`): did not start = no record; finished = exactly K items, all done, no split
 *    after the time and the last item's split (when given) = the time; unfinished = at least one item not done and
 *    pieces placed = Σ pieces of the done items + Σ `piecesPlaced` of the others; no result yet = anything.
 *
 * The browser twin is assets/official_results_puzzles.js, pinned by OfficialResultsPuzzlesParityTest - change both.
 */
final readonly class RoundEntryPuzzles
{
    // Far more than any round has puzzles - only stops a payload of a different kind
    public const int MAX_ITEMS = 50;

    /**
     * @param non-empty-list<RoundEntryPuzzle> $items in solving order
     */
    private function __construct(
        public array $items,
    ) {
    }

    /**
     * @param list<RoundEntryPuzzle> $items
     */
    public static function of(array $items): null|self
    {
        return $items === [] ? null : new self($items);
    }

    /**
     * Shape only - which puzzles and ranges are the rules' business (itemsRefusal(), consistencyRefusal()).
     *
     * @throws \InvalidArgumentException
     */
    public static function fromWire(mixed $value): null|self
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Puzzles are null or a list.');
        }

        if (count($value) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(sprintf('At most %d puzzles.', self::MAX_ITEMS));
        }

        $items = [];
        foreach ($value as $item) {
            $items[] = self::item($item);
        }

        return self::of($items);
    }

    /**
     * What `result_puzzles` holds - written only by recordPuzzles() from a value of this class.
     */
    public static function fromStored(mixed $value): null|self
    {
        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return self::fromWire($value);
    }

    /**
     * @return list<array{roundPuzzleId: string, done: bool, atSeconds?: int, piecesPlaced?: int}>
     */
    public function toWire(): array
    {
        return array_map(static fn (RoundEntryPuzzle $item): array => $item->toWire(), $this->items);
    }

    public static function same(null|self $a, null|self $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (count($a->items) !== count($b->items)) {
            return false;
        }

        foreach ($a->items as $index => $item) {
            if ($item->equals($b->items[$index]) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rules 2-5 against the round: K and the piece count of each of its puzzles.
     *
     * @param array<string, int> $piecesByRoundPuzzleId every puzzle of the round
     */
    public function itemsRefusal(int $toSolve, array $piecesByRoundPuzzleId): null|string
    {
        $seen = [];
        foreach ($this->items as $item) {
            if (!isset($piecesByRoundPuzzleId[$item->roundPuzzleId])) {
                return 'unknown_round_puzzle';
            }

            if (isset($seen[$item->roundPuzzleId])) {
                return 'puzzle_listed_twice';
            }

            $seen[$item->roundPuzzleId] = true;
        }

        if (count($this->items) > $toSolve) {
            return 'too_many_puzzles';
        }

        foreach ($this->items as $item) {
            if ($item->atSeconds !== null && ($item->atSeconds < 1 || $item->atSeconds > RoundEntryResult::MAX_SECONDS)) {
                return 'invalid_split';
            }

            if ($item->piecesPlaced !== null && ($item->piecesPlaced < 1 || $item->piecesPlaced > $piecesByRoundPuzzleId[$item->roundPuzzleId] - 1)) {
                return 'invalid_pieces_placed';
            }
        }

        $notDoneSeen = false;
        $lastSplit = null;
        foreach ($this->items as $item) {
            if ($item->done === false) {
                $notDoneSeen = true;

                continue;
            }

            if ($notDoneSeen) {
                return 'splits_out_of_order';
            }

            if ($item->atSeconds !== null) {
                if ($lastSplit !== null && $item->atSeconds <= $lastSplit) {
                    return 'splits_out_of_order';
                }

                $lastSplit = $item->atSeconds;
            }
        }

        return null;
    }

    /**
     * Rule 6 - the record against the entry's result, both as they are after the whole change set.
     *
     * @param array<string, int> $piecesByRoundPuzzleId
     */
    public static function consistencyRefusal(RoundEntryResult $result, null|self $puzzles, int $toSolve, array $piecesByRoundPuzzleId): null|string
    {
        if ($puzzles === null || $result->isNone()) {
            return null;
        }

        if ($result->didNotStart) {
            return 'puzzles_mismatch';
        }

        if ($result->seconds !== null) {
            if (count($puzzles->items) !== $toSolve) {
                return 'puzzles_mismatch';
            }

            foreach ($puzzles->items as $item) {
                if ($item->done === false || ($item->atSeconds !== null && $item->atSeconds > $result->seconds)) {
                    return 'puzzles_mismatch';
                }
            }

            $last = $puzzles->items[count($puzzles->items) - 1];

            return $last->atSeconds !== null && $last->atSeconds !== $result->seconds ? 'puzzles_mismatch' : null;
        }

        $anyNotDone = false;
        foreach ($puzzles->items as $item) {
            $anyNotDone = $anyNotDone || $item->done === false;
        }

        if ($anyNotDone === false || $puzzles->piecesPlaced($piecesByRoundPuzzleId) !== $result->piecesPlaced) {
            return 'puzzles_mismatch';
        }

        return null;
    }

    /**
     * The entry's total pieces placed (rule 6): a done puzzle counts all its pieces, one not done its `piecesPlaced`.
     *
     * @param array<string, int> $piecesByRoundPuzzleId
     */
    public function piecesPlaced(array $piecesByRoundPuzzleId): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->done ? ($piecesByRoundPuzzleId[$item->roundPuzzleId] ?? 0) : ($item->piecesPlaced ?? 0);
        }

        return $total;
    }

    /**
     * The pieces of the chosen puzzles - what the entry places when it finishes them all.
     *
     * @param array<string, int> $piecesByRoundPuzzleId
     */
    public function piecesCount(array $piecesByRoundPuzzleId): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $piecesByRoundPuzzleId[$item->roundPuzzleId] ?? 0;
        }

        return $total;
    }

    /**
     * The most pieces an entry of the round can place: its K biggest puzzles (K × the biggest is the write path's looser
     * typo bound, unfinishedMaximum()).
     *
     * @param array<string, int> $piecesByRoundPuzzleId
     */
    public static function roundPiecesBound(int $toSolve, array $piecesByRoundPuzzleId): int
    {
        $counts = array_values($piecesByRoundPuzzleId);
        rsort($counts);

        return array_sum(array_slice($counts, 0, max(0, $toSolve)));
    }

    /**
     * The most pieces placed an unfinished result of a `pick` round may have when no record says more (A §2.2):
     * K × the round's biggest puzzle − 1.
     *
     * @param array<string, int> $piecesByRoundPuzzleId
     */
    public static function unfinishedMaximum(int $toSolve, array $piecesByRoundPuzzleId): int
    {
        return $piecesByRoundPuzzleId === [] ? 0 : $toSolve * max($piecesByRoundPuzzleId) - 1;
    }

    /**
     * The item's own duration: its split minus the previous item's (the first item: its split) - null when a split it
     * needs is missing.
     */
    public function duration(int $index): null|int
    {
        $item = $this->items[$index] ?? null;

        if ($item === null || $item->atSeconds === null) {
            return null;
        }

        if ($index === 0) {
            return $item->atSeconds;
        }

        $previous = ($this->items[$index - 1] ?? null)?->atSeconds;

        return $previous !== null ? $item->atSeconds - $previous : null;
    }

    /**
     * @return list<string>
     */
    public function roundPuzzleIds(): array
    {
        return array_map(static fn (RoundEntryPuzzle $item): string => $item->roundPuzzleId, $this->items);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function item(mixed $item): RoundEntryPuzzle
    {
        if (!is_array($item)) {
            throw new \InvalidArgumentException('A puzzle is an object.');
        }

        $unknown = array_diff(array_keys($item), ['roundPuzzleId', 'done', 'atSeconds', 'piecesPlaced']);
        $roundPuzzleId = $item['roundPuzzleId'] ?? null;
        $done = $item['done'] ?? null;
        $atSeconds = $item['atSeconds'] ?? null;
        $piecesPlaced = $item['piecesPlaced'] ?? null;

        if (
            $unknown !== []
            || !is_string($roundPuzzleId) || !Uuid::isValid($roundPuzzleId)
            || !is_bool($done)
            || ($atSeconds !== null && (!is_int($atSeconds) || $done === false))
            || ($piecesPlaced !== null && (!is_int($piecesPlaced) || $done === true))
        ) {
            throw new \InvalidArgumentException('A puzzle is {"roundPuzzleId", "done", "atSeconds"?, "piecesPlaced"?}.');
        }

        return new RoundEntryPuzzle(strtolower($roundPuzzleId), $done, $atSeconds, $piecesPlaced);
    }
}
