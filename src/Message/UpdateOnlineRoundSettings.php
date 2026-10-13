<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;

/**
 * The online timing settings of a round, all at once (OnlineRound::changeSettings()).
 */
readonly final class UpdateOnlineRoundSettings
{
    public function __construct(
        public string $roundId,
        public OnlineDetectionMode $detectionMode,
        public bool $videoEnabled,
        public bool $participantsSeeEachOther,
        public bool $studioEnabled,
        public bool $recordingEnabled,
        public OnlineUnfinishedMeasure $unfinishedMeasure,
        public OnlineMissingPieceRule $missingPieceRule,
    ) {
    }
}
