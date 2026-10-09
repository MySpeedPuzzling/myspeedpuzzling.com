<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\UnpublishBlocker;

/**
 * What keeps an event (or a series, through its editions) from going back to draft (UnpublishBlockers).
 */
readonly final class UnpublishCheck
{
    public function __construct(
        public int $participants,
        public int $results,
        public int $solvingTimes,
    ) {
    }

    /**
     * @return list<UnpublishBlocker>
     */
    public function blockers(): array
    {
        $blockers = [];

        if ($this->participants > 0) {
            $blockers[] = UnpublishBlocker::Participants;
        }

        if ($this->results > 0) {
            $blockers[] = UnpublishBlocker::Results;
        }

        if ($this->solvingTimes > 0) {
            $blockers[] = UnpublishBlocker::SolvingTimes;
        }

        return $blockers;
    }

    public function allowed(): bool
    {
        return $this->blockers() === [];
    }
}
