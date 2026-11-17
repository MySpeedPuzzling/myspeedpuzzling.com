<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Services\EventWizard\EventWizardDefaults;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The event creation wizard's "Dates + each date's round" (W-creation-wizard-research.md §3.7, W3): the rule of "Add
 * several dates" (AddEditionsFormData - the same validation, preview and names) plus what each new date's round is.
 * Read from the query string (EventWizardDatesFormType, a GET form like the classic page).
 */
final class EventWizardDatesFormData extends AddEditionsFormData
{
    // false = "No rounds - just dates on the calendar" (null - left out of a hand-made URL - = with rounds)
    public null|bool $withRounds = true;

    // null (left out) = solo
    public null|RoundCategory $category = RoundCategory::Solo;

    public null|int $teamSize = null;

    public null|int $minutesLimit = EventWizardDefaults::MINUTES_LIMIT;

    // "HH:MM", local in the series' zone - the start of every date's round
    public null|string $startTime = null;

    public function hasRounds(): bool
    {
        return $this->withRounds !== false;
    }

    public function roundCategory(): RoundCategory
    {
        return $this->category ?? RoundCategory::Solo;
    }
}
