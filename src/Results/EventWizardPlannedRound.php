<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * A round the creation wizard's Structure template generates (EventWizardStructurePlanner) - not written yet; its
 * start is the organiser's (EventWizardRoundStartsFormData).
 */
readonly final class EventWizardPlannedRound
{
    public function __construct(
        public string $name,
        public RoundCategory $category,
        public null|int $teamSize,
        public int $minutesLimit,
        // The Qualification template's stage (0-based), null for the other templates
        public null|int $stage = null,
    ) {
    }
}
