<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Dispatched after a solve was edited — the edit is semantically delete+re-add, so the
 * whole (participant, puzzle) chain is rebuilt for every affected participant (occurrence
 * promotions in both directions, removed team members cleaned up).
 */
readonly final class RecalculateXpChainForSolve
{
    public function __construct(
        public string $solvingTimeId,
        /**
         * Members before the edit (whoever left the group may have earned nothing for it and still has a chain on
         * the puzzle that changes).
         *
         * @var list<string>
         */
        public array $memberPlayerIds = [],
    ) {
    }
}
