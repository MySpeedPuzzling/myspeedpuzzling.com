<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\RoundPuzzleLabel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One round's row on the creation wizard's Puzzles grid (EventWizardPuzzlesFormType): pickers for the puzzles to add,
 * the row's secret override ('' = as the switch above, `public`, or a reveal mode) and - for a round with several
 * puzzles, toolkit only - how they are solved (one result / solve all / pick K, as the round edit form).
 */
final class EventWizardPuzzlesRowFormData
{
    public const int SLOTS = 4;

    /** @var array<int, EventWizardPuzzleSlotFormData> */
    #[Assert\Valid]
    public array $puzzles = [];

    public string $override = '';

    // The round edit form's three choices (CompetitionRoundFormData::FORMAT_CHOICE_*, reliability hardening T1): K only
    // for "Pick", "Solve all" = `pick` with K = the round's puzzles after this save (the controller sets it)
    public string $puzzleFormatChoice = CompetitionRoundFormData::FORMAT_CHOICE_TOTAL;

    #[Assert\Range(min: 1, max: 99)]
    #[Assert\When(
        expression: 'this.puzzleFormatChoice === "pick"',
        constraints: [new Assert\NotNull()],
    )]
    public null|int $puzzlesToSolve = null;

    // Per new puzzle (slot index), toolkit only (spws-wizard.md): its label ("Challenge 3", empty = the letter of its
    // place) and "Unboxed" - kept beside the slots, so Backfill's shared slot form stays as it is
    /** @var array<int, null|string> */
    #[Assert\All([new Assert\Length(max: RoundPuzzleLabel::MAX_LENGTH)])]
    public array $labels = [];

    /** @var array<int, bool> */
    public array $unboxedSlots = [];

    public function __construct()
    {
        for ($index = 0; $index < self::SLOTS; $index++) {
            $this->puzzles[$index] = new EventWizardPuzzleSlotFormData();
            $this->labels[$index] = null;
            $this->unboxedSlots[$index] = false;
        }
    }

    /**
     * @return list<EventWizardPuzzleSlotFormData>
     */
    public function chosen(): array
    {
        return array_values(array_filter($this->puzzles, static fn (EventWizardPuzzleSlotFormData $slot): bool => $slot->hasPuzzle()));
    }
}
