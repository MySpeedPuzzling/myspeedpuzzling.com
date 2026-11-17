<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The creation wizard's Puzzles grid (W-creation-wizard-research.md §3.6, EventWizardPuzzlesFormType): one switch
 * "Keep every puzzle secret until its round starts" with the reveal chosen once and what is hidden, then a row per
 * round keyed by its id.
 */
final class EventWizardPuzzlesFormData
{
    public bool $secret = false;
    public RoundPuzzleReveal $revealMode = RoundPuzzleReveal::Automatic;
    public PuzzleHideMode $hideMode = PuzzleHideMode::Entirely;

    /**
     * @param array<string, EventWizardPuzzlesRowFormData> $rows
     */
    public function __construct(
        #[Assert\Valid]
        public array $rows = [],
    ) {
    }
}
