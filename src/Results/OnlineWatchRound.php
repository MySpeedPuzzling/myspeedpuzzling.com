<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;

/**
 * A round whose studio the viewer may watch (OnlineWatchGate): the round, its online timing and whether there is live
 * video to watch - a media server is configured (LiveKitSettings) and the round's video is on.
 */
readonly final class OnlineWatchRound
{
    public function __construct(
        public CompetitionRound $round,
        public OnlineRound $onlineRound,
        // The event is public - false only for its editors looking at a draft, a pending or a rejected event
        public bool $isPublic,
        public bool $videoAvailable,
    ) {
    }
}
