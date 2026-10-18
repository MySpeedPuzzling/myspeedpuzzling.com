<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MergeDecisionSource;

readonly final class RejectPuzzleChangeRequest
{
    public function __construct(
        public string $changeRequestId,
        public string $reviewerId,
        // Shown to the player who proposed the change - the decision's `note`
        public string $rejectionReason,
        public MergeDecisionSource $decisionSource = MergeDecisionSource::AdminUi,
        // An internal note for the decision log, never shown to the player (the internal API) - the decision's
        // details.decisionNote, `note` being the rejection reason
        public null|string $decisionNote = null,
    ) {
    }
}
