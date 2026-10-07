<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Takes the entries the organiser marked qualified in one or more rounds into one or more later rounds of the same
 * category - the WJPC flow: groups A-D → semifinals S1/S2 → final (docs/features/competitions-management/official-results.md).
 * Solo: the person is added to the target round. Pair/team: a new team with the same members and name - unless that
 * exact set of people is a team there already. An entry already in any target round is skipped, so advancing twice
 * never duplicates anybody; unmarking a qualified entry later never removes anyone.
 *
 * A dry run answers the exact plan (AdvancementPlan, from the HandledStamp) with its `planHash`; applying requires that
 * hash, plans again under the lock and refuses (AdvancementPlanChanged) when anything changed meanwhile.
 */
readonly final class AdvanceQualified implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        /** @var list<string> in the organiser's order - it breaks the last ties of the seed */
        public array $sourceRoundIds,
        /** @var list<string> in the organiser's order - the serpentine of "balanced" follows it */
        public array $targetRoundIds,
        public AdvanceDistribution $distribution,
        /** @var array<string, string> source round id => target round id, "by_source" only */
        public array $targetBySource = [],
        public bool $dryRun = true,
        public null|string $planHash = null,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
