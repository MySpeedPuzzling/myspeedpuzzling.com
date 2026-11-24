<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The rules of the standings the creation wizard makes (docs/features/competitions-management/spws-wizard.md) - the
 * standing form's four rules (CompetitionStandingFormType), with its words. Untouched = new StandingRules() (today's
 * standing); the Challenges template starts from worldSeries(). Every value may be missing from a GET submit (an old
 * link): it then reads as the default.
 */
final class EventWizardStandingRulesFormData
{
    #[Assert\Range(min: 0, max: StandingRules::PENALTY_MAX)]
    public null|int $penaltySecondsPerPiece = 0;

    public bool $penaliseFinishedMissing = false;

    public null|StandingOrder $orderRule = StandingOrder::FinishedRoundsFirst;

    public null|StandingMissing $missingRule = StandingMissing::Incomplete;

    /**
     * The Speed Puzzle World Series rules: 5 s per missing piece, also when finished with pieces missing; ranked by the
     * total time only; a no-show counts with every piece missing (world-series-rules.md §4)
     */
    public static function worldSeries(): self
    {
        $rules = new self();
        $rules->penaltySecondsPerPiece = 5;
        $rules->penaliseFinishedMissing = true;
        $rules->orderRule = StandingOrder::TotalOnly;
        $rules->missingRule = StandingMissing::AsZeroPieces;

        return $rules;
    }

    public function toRules(): StandingRules
    {
        return new StandingRules(
            penaltySecondsPerPiece: $this->penaltySecondsPerPiece ?? 0,
            order: $this->orderRule ?? StandingOrder::FinishedRoundsFirst,
            missing: $this->missingRule ?? StandingMissing::Incomplete,
            penaliseFinishedMissing: $this->penaliseFinishedMissing,
        );
    }
}
