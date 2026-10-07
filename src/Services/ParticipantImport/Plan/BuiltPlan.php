<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use SpeedPuzzling\Web\Results\ParticipantImportRemovals;
use SpeedPuzzling\Web\Results\ParticipantImportRow;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * What one PlanBuilder run produced - ParticipantImportPlanner turns it into a ParticipantImportPlan.
 */
readonly final class BuiltPlan
{
    /**
     * @param list<ParticipantImportRow> $rows
     * @param list<TranslatableMessage> $warnings
     * @param list<TranslatableMessage> $syncBlockers
     */
    public function __construct(
        public array $rows,
        public array $warnings,
        public ParticipantImportRemovals $removals,
        public ParticipantImportOperations $operations,
        public array $syncBlockers,
        public int $activeParticipantsBefore,
        /** sha256 of what full sync keeps because of results */
        public string $resultsGuard,
    ) {
    }
}
