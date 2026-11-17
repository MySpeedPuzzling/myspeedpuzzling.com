<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Structure step's start times (EventWizardRoundStartsFormType): one row per round the chosen template generates
 * (EventWizardStructurePlanner, in its order) - every round's day and time are the organiser's.
 */
final class EventWizardRoundStartsFormData
{
    /**
     * @param array<int, EventWizardRoundStartFormData> $rows
     */
    public function __construct(
        #[Assert\Valid]
        public array $rows = [],
    ) {
    }
}
