<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The online round's reference image goes (docs/features/online-competitions/README.md §5.8): the completeness check
 * uses the round puzzle's catalogue image again; the object is deleted after the commit. Nothing happens for a round
 * without one. The round must use online timing (OnlineRoundNotFound otherwise).
 */
readonly final class RemoveOnlineRoundReferenceImage
{
    public function __construct(
        public string $roundId,
    ) {
    }
}
