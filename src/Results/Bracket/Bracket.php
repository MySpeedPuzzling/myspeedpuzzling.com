<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Bracket;

use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The bracket of one category (competition toolkit A9, BracketLayout): stages as columns (groups → semifinals →
 * final), the rounds of each stage in schedule order, the lines between neighbouring stages and who feeds each round.
 */
readonly final class Bracket
{
    /**
     * @param list<list<BracketRound>> $stages stage 1 first (never empty: a step has a source and a target)
     * @param list<list<BracketConnector>> $gaps the lines between stage n and n + 1 (index n - 1), one list fewer than stages
     * @param array<string, BracketFeed> $feeds round id => what feeds it (rounds of stage 1 have none)
     */
    public function __construct(
        public RoundCategory $category,
        public array $stages,
        public array $gaps,
        public array $feeds,
    ) {
    }

    public function feedOf(string $roundId): null|BracketFeed
    {
        return $this->feeds[$roundId] ?? null;
    }

    /**
     * @return list<BracketRound>
     */
    public function rounds(): array
    {
        return array_merge(...$this->stages);
    }

    /**
     * @param callable(BracketRound): BracketRound $change
     */
    public function mapRounds(callable $change): self
    {
        return new self(
            $this->category,
            array_map(static fn (array $stage): array => array_map($change, $stage), $this->stages),
            $this->gaps,
            $this->feeds,
        );
    }
}
