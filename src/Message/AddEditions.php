<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\NewEdition;

/**
 * "Add several dates" (docs/features/organizations/README.md, D15): up to MAX real editions of one series in one step,
 * each dated one day. No recurrence is stored - a venue reschedule is an edit of that one edition.
 */
readonly final class AddEditions
{
    public const int MAX = 24;

    /**
     * @param list<NewEdition> $editions
     */
    public function __construct(
        public string $seriesId,
        public array $editions,
        public null|string $eligibility = null,
        public bool $isDraft = false,
    ) {
    }
}
