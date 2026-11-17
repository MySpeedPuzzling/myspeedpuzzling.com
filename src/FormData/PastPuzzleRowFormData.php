<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One row of the backfill path's "What did you solve there?" (creation-wizard.md "Backfill"): solo / pairs / teams and
 * the puzzle - W1's picker (EventWizardPuzzleSlotFormData: a catalogue puzzle or a new one by brand, name and pieces;
 * empty = no row). Each filled row becomes a round of its own.
 */
final class PastPuzzleRowFormData
{
    // null = a row not shown (behind "+ another puzzle") - never filled, never made a round
    public null|RoundCategory $category = RoundCategory::Solo;

    #[Assert\Valid]
    public EventWizardPuzzleSlotFormData $slot;

    public function __construct()
    {
        $this->slot = new EventWizardPuzzleSlotFormData();
    }
}
