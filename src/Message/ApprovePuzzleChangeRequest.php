<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\ReviewedPuzzleValues;

readonly final class ApprovePuzzleChangeRequest
{
    /**
     * @param list<string> $selectedFields Proposed fields applied as proposed, the rest of the puzzle stays as it
     *                                     is (internal API). Not used when $reviewed is given.
     */
    public function __construct(
        public string $changeRequestId,
        public string $reviewerId,
        public array $selectedFields = [],
        // The admin review: the whole puzzle as the reviewer wants it, proposed fields or not
        public null|ReviewedPuzzleValues $reviewed = null,
        public MergeDecisionSource $decisionSource = MergeDecisionSource::AdminUi,
        public null|string $decisionNote = null,
    ) {
    }
}
