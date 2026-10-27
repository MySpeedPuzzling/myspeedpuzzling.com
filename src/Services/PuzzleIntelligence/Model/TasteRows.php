<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Value\PictureFactors;
use SplFixedArray;

/**
 * The first tries the taste factor is fitted on (TasteFitter), column by column: the player and the puzzle (ints of the
 * samples' dictionaries), the piece count, the moment, the residual against the candidate's centre without the taste
 * (clipped) and whether it was at an event; the puzzles' pictures by their int.
 */
final class TasteRows
{
    /** @var SplFixedArray<int> */
    public SplFixedArray $player;
    /** @var SplFixedArray<int> */
    public SplFixedArray $puzzle;
    /** @var SplFixedArray<int> */
    public SplFixedArray $pieces;
    /** @var SplFixedArray<float> */
    public SplFixedArray $solved;
    /** @var SplFixedArray<float> */
    public SplFixedArray $tracked;
    /** @var SplFixedArray<float> */
    public SplFixedArray $residual;
    /** @var SplFixedArray<bool> */
    public SplFixedArray $competition;
    /** @var SplFixedArray<null|float> v7: the puzzle's posterior log difficulty as of the first try (the player's slope) */
    public SplFixedArray $logDifficulty;
    private int $count = 0;

    /**
     * @param array<int, PictureFactors> $pictures puzzle int => its picture (none = no factors, no kind)
     */
    public function __construct(
        int $capacity,
        public array $pictures = [],
    ) {
        $this->player = new SplFixedArray($capacity);
        $this->puzzle = new SplFixedArray($capacity);
        $this->pieces = new SplFixedArray($capacity);
        $this->solved = new SplFixedArray($capacity);
        $this->tracked = new SplFixedArray($capacity);
        $this->residual = new SplFixedArray($capacity);
        $this->competition = new SplFixedArray($capacity);
        $this->logDifficulty = new SplFixedArray($capacity);
    }

    public function add(int $player, int $puzzle, int $pieces, float $solved, float $tracked, float $residual, bool $competition, null|float $logDifficulty = null): void
    {
        $r = $this->count++;
        $this->player[$r] = $player;
        $this->puzzle[$r] = $puzzle;
        $this->pieces[$r] = $pieces;
        $this->solved[$r] = $solved;
        $this->tracked[$r] = $tracked;
        $this->residual[$r] = $residual;
        $this->competition[$r] = $competition;
        $this->logDifficulty[$r] = $logDifficulty;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function picture(int $r): null|PictureFactors
    {
        return $this->pictures[(int) $this->puzzle[$r]] ?? null;
    }

    /**
     * Every row in the order of its moment (solved, then tracked) - the as-of order.
     *
     * @return list<int>
     */
    public function inTimeOrder(): array
    {
        if ($this->count === 0) {
            return [];
        }

        $order = range(0, $this->count - 1);
        $solved = $this->solved->toArray();
        $tracked = $this->tracked->toArray();
        usort($order, static fn (int $a, int $b): int => $solved[$a] <=> $solved[$b] ?: $tracked[$a] <=> $tracked[$b] ?: $a <=> $b);

        return $order;
    }
}
