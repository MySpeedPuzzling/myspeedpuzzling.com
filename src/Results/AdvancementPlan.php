<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What AdvanceQualified does (dry run) or did (applied): who goes where, who is skipped and why
 * (`already_in_target`, `member_already_in_target`, `qualified_twice` - official_results.advance.skip.*), and the
 * target rounds' entry counts before and after. `planHash` binds the organiser's confirmation to exactly this plan.
 */
readonly final class AdvancementPlan implements \JsonSerializable
{
    public function __construct(
        public string $planHash,
        public bool $applied,
        /** @var list<AdvancementAssignment> in seed order */
        public array $assignments,
        /** @var list<array{seed: int, sourceRoundId: string, entry: RoundResultEntry, reason: string}> */
        public array $skipped,
        /** @var list<array{roundId: string, name: string, entriesBefore: int, entriesAfter: int}> */
        public array $targets,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'planHash' => $this->planHash,
            'applied' => $this->applied,
            'assignments' => $this->assignments,
            'skipped' => $this->skipped,
            'targets' => $this->targets,
        ];
    }
}
