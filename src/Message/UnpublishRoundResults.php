<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Takes the round's official results off its public round page - nothing recorded changes, a later publish shows them
 * again (without telling the players a second time).
 */
readonly final class UnpublishRoundResults
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
    ) {
    }
}
