<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One round's row on the creation wizard's Puzzles grid (EventWizardPuzzlesFormType): pickers for the puzzles to add,
 * the row's secret override ('' = as the switch above, `public`, or a reveal mode) and - for a round with several
 * puzzles, toolkit only - how many each competitor solves (empty = all).
 */
final class EventWizardPuzzlesRowFormData
{
    public const int SLOTS = 4;

    /** @var array<int, EventWizardPuzzleSlotFormData> */
    #[Assert\Valid]
    public array $puzzles = [];

    public string $override = '';

    #[Assert\Range(min: 1, max: 99)]
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
