<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantsSheet\Plan;

use SpeedPuzzling\Web\Results\SheetGroupOutcome;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;

/**
 * What SheetChangesPlanner decided: the answer per group, and what to write for the groups that went through - the net
 * difference between the event before and after them, as the import's operations (ParticipantImportApplier) plus the
 * rounds' expected team sizes.
 */
readonly final class SheetPlan
{
    /**
     * @param list<SheetGroupOutcome> $groups
     * @param array<string, null|int> $teamSizes round id => its new expected team size
     */
    public function __construct(
        public array $groups,
        public ParticipantImportOperations $operations,
        public array $teamSizes,
    ) {
    }
}
