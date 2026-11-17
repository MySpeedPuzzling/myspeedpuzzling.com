<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use JsonSerializable;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;

/**
 * An advancement step as the organiser's pages read it (GetCompetitionAdvancementSteps). Its JSON is what the results
 * desk hands the Advance dialog to prefill (advance_qualified_controller.js `openStep`, official_results_qualification.js
 * `prefillFromStep`) - the same names AdvanceQualified's endpoint takes.
 */
readonly final class AdvancementStepOverview implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $competitionId,
        public null|string $name,
        public AdvancementStepSettings $settings,
        public int $position,
    ) {
    }

    public function hasSource(string $roundId): bool
    {
        return in_array(strtolower($roundId), $this->settings->sourceRoundIds, true);
    }

    public function isOverall(): bool
    {
        return $this->settings->qualifyScope === AdvancementQualifyScope::Overall && count($this->settings->sourceRoundIds) > 1;
    }

    /**
     * @return array{id: string, name: null|string, sourceRoundIds: list<string>, targetRoundIds: list<string>, distribution: string, targetBySource: array<string, string>, qualifyCount: null|int, qualifyScope: string, bestOfEachCountry: null|int}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sourceRoundIds' => $this->settings->sourceRoundIds,
            'targetRoundIds' => $this->settings->targetRoundIds,
            'distribution' => $this->settings->distribution->value,
            'targetBySource' => $this->settings->targetBySource,
            'qualifyCount' => $this->settings->qualifyCount,
            'qualifyScope' => $this->settings->qualifyScope->value,
            'bestOfEachCountry' => $this->settings->bestOfEachCountry,
        ];
    }
}
