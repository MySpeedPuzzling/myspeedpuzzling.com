<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One puzzle picker of a round's row on the creation wizard's Puzzles grid (EventWizardPuzzleSlotFormType): a catalogue
 * puzzle, or a new one by its brand, name and pieces - or nothing ("Decide later"). A new puzzle's photo and codes are
 * added on the round's own puzzle form.
 */
final class EventWizardPuzzleSlotFormData
{
    public function __construct(
        public null|string $brand = null,
        public null|string $puzzle = null,
        #[Assert\Range(min: 1, max: 99999)]
        public null|int $piecesCount = null,
    ) {
    }

    public function hasPuzzle(): bool
    {
        return $this->puzzle !== null && trim($this->puzzle) !== '';
    }

    public function hasBrand(): bool
    {
        return $this->brand !== null && trim($this->brand) !== '';
    }

    /**
     * The catalogue puzzle's id - null for a new puzzle or none
     */
    public function catalogueId(): null|string
    {
        return $this->hasPuzzle() && Uuid::isValid(trim((string) $this->puzzle)) ? strtolower(trim((string) $this->puzzle)) : null;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->hasPuzzle() && $this->hasBrand() === false) {
            $context->buildViolation('This value should not be blank.')->atPath('brand')->addViolation();
        }

        if ($this->hasBrand() && $this->hasPuzzle() === false) {
            $context->buildViolation('This value should not be blank.')->atPath('puzzle')->addViolation();
        }

        // A new puzzle needs its pieces - the round's own form takes its photo and codes later
        if ($this->hasPuzzle() && $this->catalogueId() === null && $this->piecesCount === null) {
            $context->buildViolation('This value should not be blank.')->atPath('piecesCount')->addViolation();
        }
    }
}
