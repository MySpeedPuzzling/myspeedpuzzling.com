<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Structure step's schedule table (EventWizardScheduleFormType): the event's rounds keyed by id plus spare rows for
 * new ones keyed `new0`, `new1` …
 */
final class EventWizardScheduleFormData
{
    public const int NEW_ROWS = 3;

    /**
     * @param array<string, EventWizardScheduleRowFormData> $rows
     */
    public function __construct(
        #[Assert\Valid]
        public array $rows = [],
    ) {
    }

    /**
     * @return array<string, EventWizardScheduleRowFormData>
     */
    public function usedRows(): array
    {
        return array_filter($this->rows, static fn (EventWizardScheduleRowFormData $row): bool => $row->isUsed());
    }
}
