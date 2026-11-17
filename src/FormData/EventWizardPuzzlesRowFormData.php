<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

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

    public function __construct()
    {
        for ($index = 0; $index < self::SLOTS; $index++) {
            $this->puzzles[$index] = new EventWizardPuzzleSlotFormData();
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
