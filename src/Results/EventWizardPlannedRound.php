<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * A round the creation wizard's Structure template generates (EventWizardStructurePlanner) - not written yet.
 */
readonly final class EventWizardPlannedRound
{
    public function __construct(
        public string $name,
        public RoundCategory $category,
        public null|int $teamSize,
        public int $minutesLimit,
        // The local start ("Y-m-d H:i") in the zone of the event's country
        public string $startsLocal,
        // The Qualification template's stage (0-based), null for the other templates
        public null|int $stage = null,
    ) {
    }
}
