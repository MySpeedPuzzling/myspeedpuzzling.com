<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * Re-ranks the stored places of one day of player_rating_snapshot by the ladder's rule (RerankRatingSnapshotsHandler).
 * `myspeedpuzzling:rerank-rating-snapshots` dispatches one per stored day, so each day is its own transaction. Run once
 * after the rule changed (docs/features/msp-rating-history.md); safe to run again.
 */
readonly final class RerankRatingSnapshots
{
    public function __construct(
        public DateTimeImmutable $day,
    ) {
    }
}
