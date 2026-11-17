<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The event creation wizard's "Puzzle & format" step (EventWizardPuzzleFormType): the single round's who competes and
 * time limit, a puzzle to add (optional - "Decide later"; the fields of RoundPuzzleFormType), whether it is secret and
 * when it is revealed, and - for a round with several puzzles, toolkit only - how many each competitor solves.
 */
final class EventWizardPuzzleFormData
{
    /**
     * @param array<int, null|string> $puzzleEans One input per barcode (CodeListType)
     * @param array<int, null|string> $puzzleBrandCodes One input per brand code
     */
    public function __construct(
        public RoundCategory $category = RoundCategory::Solo,
        #[Assert\When(
            expression: 'this.category.value === "team"',
            constraints: [new Assert\Range(min: CompetitionRound::TEAM_SIZE_MIN, max: CompetitionRound::TEAM_SIZE_MAX)],
        )]
        public null|int $teamSize = null,
        #[Assert\NotBlank]
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(1440)]
        public null|int $minutesLimit = null,
        public null|string $brand = null,
        public null|string $puzzle = null,
        #[Assert\Range(min: 1, max: 99999)]
        public null|int $piecesCount = null,
        public null|UploadedFile $puzzlePhoto = null,
        #[Assert\Count(max: EanList::FORM_MAX_CODES)]
        #[Assert\All([new Assert\Length(max: 30)])]
        public array $puzzleEans = [],
        #[Assert\Count(max: BrandCodeList::FORM_MAX_CODES)]
        #[Assert\All([new Assert\Length(max: 50)])]
        public array $puzzleBrandCodes = [],
        public bool $hideUntilRoundStarts = false,
        public PuzzleHideMode $hideMode = PuzzleHideMode::Entirely,
        // When a secret puzzle comes out - the round's automatic reveal unless the organiser picks another
        public RoundPuzzleReveal $revealMode = RoundPuzzleReveal::Automatic,
        // Competition toolkit A2 - on the form only with several puzzles and for whom COMPETITION_TOOLKIT_MANAGE grants
        #[Assert\When(
            expression: 'this.puzzleFormatChoice === "pick"',
            constraints: [new Assert\NotNull(), new Assert\Positive()],
        )]
        public null|int $puzzlesToSolve = null,
        // The three choices of the round edit form (reliability hardening T1) - CompetitionRoundFormData::FORMAT_CHOICE_*:
        // "Solve all N" is `pick` with K = the round's puzzles, set by the controller (a puzzle added on this submit counts)
        public string $puzzleFormatChoice = CompetitionRoundFormData::FORMAT_CHOICE_TOTAL,
    ) {
    }

    /**
     * The model the choice stands for: `total`, or `pick` with K - "Solve all" = K = the round's puzzles after this save
     *
     * @return array{0: RoundPuzzleFormat, 1: null|int}
     */
    public function chosenFormat(int $puzzlesAfterSave): array
    {
        return match ($this->puzzleFormatChoice) {
            CompetitionRoundFormData::FORMAT_CHOICE_ALL => [RoundPuzzleFormat::Pick, max(1, $puzzlesAfterSave)],
            CompetitionRoundFormData::FORMAT_CHOICE_PICK => [RoundPuzzleFormat::Pick, $this->puzzlesToSolve],
            default => [RoundPuzzleFormat::Total, null],
        };
    }

    /**
     * A puzzle is chosen (a catalogue puzzle's id, or a new puzzle's typed name)
     */
    public function hasPuzzle(): bool
    {
        return $this->puzzle !== null && trim($this->puzzle) !== '';
    }

    public function hasBrand(): bool
    {
        return $this->brand !== null && trim($this->brand) !== '';
    }

    /**
     * Brand and puzzle go together; a new puzzle's codes follow the rule of every other form (RoundPuzzleFormData).
     */
    #[Assert\Callback]
    public function validatePuzzle(ExecutionContextInterface $context): void
    {
        if ($this->hasPuzzle() && $this->hasBrand() === false) {
            $context->buildViolation('This value should not be blank.')->atPath('brand')->addViolation();
        }

        if ($this->hasBrand() && $this->hasPuzzle() === false) {
            $context->buildViolation('This value should not be blank.')->atPath('puzzle')->addViolation();
        }

        if ($this->hasPuzzle() === false || Uuid::isValid((string) $this->puzzle)) {
            return;
        }

        EanList::addInputViolations($context, 'puzzleEans', $this->puzzleEans, null);
        BrandCodeList::addViolations($context, 'puzzleBrandCodes', $this->puzzleBrandCodes);
    }
}
