<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionAdvancementStep;
use SpeedPuzzling\Web\Results\AdvancementStepOverview;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An advancement step's settings (competition toolkit A8). Whether the rounds and the distribution fit together and
 * with the event's other steps is the handler's check (InvalidAdvancementStep → a form error).
 */
final class AdvancementStepFormData
{
    #[Assert\Length(max: CompetitionAdvancementStep::NAME_MAX_LENGTH)]
    public null|string $name = null;

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'advancement_step.refused.no_sources')]
    public array $sourceRoundIds = [];

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'advancement_step.refused.no_targets')]
    public array $targetRoundIds = [];

    #[Assert\NotNull]
    public null|AdvanceDistribution $distribution = AdvanceDistribution::Single;

    /** @var array<string, null|string> source round id => target round id (`by_source` only) */
    public array $targetBySource = [];

    #[Assert\Range(min: 1, max: AdvancementStepSettings::QUALIFY_MAX)]
    public null|int $qualifyCount = null;

    #[Assert\NotNull]
    public null|AdvancementQualifyScope $qualifyScope = AdvancementQualifyScope::PerRound;

    #[Assert\Range(min: 1, max: AdvancementStepSettings::COUNTRY_MAX)]
    public null|int $bestOfEachCountry = null;

    public static function fromStep(AdvancementStepOverview $step): self
    {
        $data = new self();
        $data->name = $step->name;
        $data->sourceRoundIds = $step->settings->sourceRoundIds;
        $data->targetRoundIds = $step->settings->targetRoundIds;
        $data->distribution = $step->settings->distribution;
        $data->targetBySource = $step->settings->targetBySource;
        $data->qualifyCount = $step->settings->qualifyCount;
        $data->qualifyScope = $step->settings->qualifyScope;
        $data->bestOfEachCountry = $step->settings->bestOfEachCountry;

        return $data;
    }

    public function settings(): AdvancementStepSettings
    {
        return new AdvancementStepSettings(
            sourceRoundIds: $this->sourceRoundIds,
            targetRoundIds: $this->targetRoundIds,
            distribution: $this->distribution ?? AdvanceDistribution::Single,
            targetBySource: array_filter($this->targetBySource, static fn (null|string $target): bool => $target !== null && $target !== ''),
            qualifyCount: $this->qualifyCount,
            qualifyScope: $this->qualifyScope ?? AdvancementQualifyScope::PerRound,
            bestOfEachCountry: $this->bestOfEachCountry,
        );
    }
}
