<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

use SpeedPuzzling\Web\Value\RoundResultStatus;

/**
 * One team of an event's preview, ranked exactly as the round's results page ranks the organizer's results
 * (GetRoundResults::order()).
 */
readonly final class PreviewResultRow
{
    public function __construct(
        public string $teamName,
        public int $teamSize,
        public RoundResultStatus $status,
        public null|int $seconds,
        public null|int $piecesPlaced,
        public null|int $rank,
        public null|int $gapToLeader,
        public null|int $gapToFaster,
        public int $line,
    ) {
    }
}
