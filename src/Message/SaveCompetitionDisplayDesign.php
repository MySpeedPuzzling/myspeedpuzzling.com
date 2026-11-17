<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionDisplaysLock;

/**
 * The editor's Save (D-displays-overlays.md §3.3, §8.1): the design as the browser sent it - decoded with objects as
 * stdClass, so an object and a list stay apart - on top of the version the editor started from. Saves of one display
 * take turns, so the version check and the write are one step.
 *
 * `liveConfirmed`: the organiser confirmed showing results before they are published (D §5.1) - required for every
 * results element that was not live in the saved design yet.
 */
readonly final class SaveCompetitionDisplayDesign implements SerializedByLock
{
    public function __construct(
        public string $displayId,
        public mixed $design,
        public int $baseVersion,
        public bool $liveConfirmed = false,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionDisplaysLock::display($this->displayId);
    }
}
