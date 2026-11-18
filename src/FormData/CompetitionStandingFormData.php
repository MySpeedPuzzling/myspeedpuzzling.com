<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingMode;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A standing's settings (competition toolkit A6): name, category, rounds, penalty per missing piece, order, what a
 * round without a result does. Whether the
 * rounds fit the event and the category is the handler's check (InvalidCompetitionStanding → a form error).
 */
final class CompetitionStandingFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: CompetitionStanding::NAME_MAX_LENGTH)]
    public null|string $name = null;

    #[Assert\NotNull]
    public null|RoundCategory $category = RoundCategory::Solo;

    // How it is decided (competition toolkit v2 S5): the sum of chosen rounds, or the final classification across the
    // bracket (its rounds come from the advancement steps - the rounds, penalty, order and missing rule are not used)
    #[Assert\NotNull]
    public null|StandingMode $mode = StandingMode::Sum;

    /** @var list<string> */
    #[Assert\When(
        expression: 'this.mode === null or this.mode.value !== "stages"',
        constraints: [new Assert\Count(min: 1, minMessage: 'competition_standing.refused.no_rounds')],
    )]
    public array $roundIds = [];

    // `stages`: one number over everybody across the stages
    public bool $numberedAcrossStages = false;

    // Real events L2a: limited to one division of the event (`sum` only) - null = everybody. The field is there only when
    // the event has divisions; the handler checks the division is the event's and every chosen round uses it
    public null|string $divisionId = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: StandingRules::PENALTY_MAX)]
    public null|int $penaltySecondsPerPiece = 0;

    #[Assert\NotNull]
    public null|StandingOrder $orderRule = StandingOrder::FinishedRoundsFirst;

    // Competition toolkit T6 - the penalty also for pieces a finished puzzle lacked
    public bool $penaliseFinishedMissing = false;

    // A round without a result: listed after everybody (default) or a no-show scored as nothing placed (World Series)
    #[Assert\NotNull]
    public null|StandingMissing $missingRule = StandingMissing::Incomplete;

    public static function fromStanding(CompetitionStandingOverview $standing): self
    {
        $data = new self();
        $data->name = $standing->name;
        $data->category = $standing->category;
        $data->roundIds = $standing->roundIds;
        $data->penaltySecondsPerPiece = $standing->rules->penaltySecondsPerPiece;
        $data->orderRule = $standing->rules->order;
        $data->penaliseFinishedMissing = $standing->rules->penaliseFinishedMissing;
        $data->missingRule = $standing->rules->missing;
        $data->mode = $standing->mode;
        $data->numberedAcrossStages = $standing->numberedAcrossStages;
        $data->divisionId = $standing->division?->id;

        return $data;
    }

    public function rules(): StandingRules
    {
        return new StandingRules(
            $this->penaltySecondsPerPiece ?? 0,
            $this->orderRule ?? StandingOrder::FinishedRoundsFirst,
            $this->missingRule ?? StandingMissing::Incomplete,
            penaliseFinishedMissing: $this->penaliseFinishedMissing,
        );
    }
}
