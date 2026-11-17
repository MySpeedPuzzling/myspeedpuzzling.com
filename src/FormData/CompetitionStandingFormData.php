<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A standing's settings (competition toolkit A6): name, category, rounds, penalty per missing piece, order. Whether the
 * rounds fit the event and the category is the handler's check (InvalidCompetitionStanding → a form error).
 */
final class CompetitionStandingFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: CompetitionStanding::NAME_MAX_LENGTH)]
    public null|string $name = null;

    #[Assert\NotNull]
    public null|RoundCategory $category = RoundCategory::Solo;

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'competition_standing.refused.no_rounds')]
    public array $roundIds = [];

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: StandingRules::PENALTY_MAX)]
    public null|int $penaltySecondsPerPiece = 0;

    #[Assert\NotNull]
    public null|StandingOrder $orderRule = StandingOrder::FinishedRoundsFirst;

    // Competition toolkit T6 - the penalty also for pieces a finished puzzle lacked
    public bool $penaliseFinishedMissing = false;

    public static function fromStanding(CompetitionStandingOverview $standing): self
    {
        $data = new self();
        $data->name = $standing->name;
        $data->category = $standing->category;
        $data->roundIds = $standing->roundIds;
        $data->penaltySecondsPerPiece = $standing->rules->penaltySecondsPerPiece;
        $data->orderRule = $standing->rules->order;
        $data->penaliseFinishedMissing = $standing->rules->penaliseFinishedMissing;

        return $data;
    }

    public function rules(): StandingRules
    {
        return new StandingRules(
            $this->penaltySecondsPerPiece ?? 0,
            $this->orderRule ?? StandingOrder::FinishedRoundsFirst,
            penaliseFinishedMissing: $this->penaliseFinishedMissing,
        );
    }
}
