<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A resolved comparison subject ready for display: the base player, its
 * required co-solvers, an assigned color and flags.
 */
readonly final class ComparisonSubjectView
{
    public function __construct(
        public string $key,
        public ComparisonPlayer $player,
        /** @var list<ComparisonPlayer> */
        public array $coSolvers,
        public string $color,
        public bool $isSelf,
    ) {
    }

    public function label(): string
    {
        $label = $this->player->displayName();

        foreach ($this->coSolvers as $coSolver) {
            $label .= ' + ' . $coSolver->displayName();
        }

        return $label;
    }
}
