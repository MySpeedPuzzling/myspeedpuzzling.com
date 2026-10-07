<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What AdvanceQualified does (dry run) or did (applied): who goes where, who is skipped and why
 * (`already_in_target`, `member_already_in_target`, `member_already_planned`, `qualified_twice` -
 * official_results.advance.skip.*), and the target rounds' entry counts before and after. With the country rule: who it
 * marks qualified (`markedByCountryRule`, also flagged on their assignment/skip) and the ranked entries it could not
 * place for lack of a country (`withoutCountry`). `planHash` binds the organiser's confirmation to exactly this plan.
 */
readonly final class AdvancementPlan implements \JsonSerializable
{
    public function __construct(
        public string $planHash,
        public bool $applied,
        /** @var list<AdvancementAssignment> in seed order */
        public array $assignments,
        /** @var list<array{seed: int, sourceRoundId: string, entry: RoundResultEntry, reason: string, byCountryRule: bool}> */
        public array $skipped,
        /** @var list<array{roundId: string, name: string, entriesBefore: int, entriesAfter: int}> */
        public array $targets,
        public null|int $bestOfEachCountry = null,
        /** @var list<array{entry: string, sourceRoundId: string}> */
        public array $markedByCountryRule = [],
        /** @var list<array{sourceRoundId: string, entry: RoundResultEntry}> */
        public array $withoutCountry = [],
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
            'bestOfEachCountry' => $this->bestOfEachCountry,
            'markedByCountryRule' => $this->markedByCountryRule,
            'withoutCountry' => $this->withoutCountry,
        ];
    }
}
