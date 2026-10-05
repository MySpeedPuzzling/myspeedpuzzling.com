<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PuzzleAddFormData
{
    // Mode selection
    public PuzzleAddMode $mode = PuzzleAddMode::SpeedPuzzling;

    // Puzzle selection (all modes)
    public null|string $brand = null;

    public null|string $puzzle = null;

    // New puzzle fields (all modes when creating new)
    #[Positive]
    #[Range(min: 10, max: 99999)]
    public null|int $puzzlePiecesCount = null;

    public null|UploadedFile $puzzlePhoto = null;

    /**
     * The new puzzle's barcodes, one input each (CodeListType) - blank inputs are dropped
     *
     * @var array<int, null|string>
     */
    #[Count(max: EanList::FORM_MAX_CODES)]
    #[All([new Length(max: 30)])]
    public array $puzzleEans = [];

    /**
     * The new puzzle's brand codes, one input each
     *
     * @var array<int, null|string>
     */
    #[Count(max: BrandCodeList::FORM_MAX_CODES)]
    #[All([new Length(max: 50)])]
    public array $puzzleBrandCodes = [];

    /**
     * Names of other boxes of a new puzzle ("+ name in another language") - rows without a name are dropped
     *
     * @var array<PuzzleNameFormData>
     */
    #[Valid]
    public array $alternativeNames = [];

    // Speed Puzzling specific - time as separate fields
    #[PositiveOrZero]
    #[Range(max: 999)]
    public int $timeHours = 0;

    #[PositiveOrZero]
    #[Range(max: 59)]
    public int $timeMinutes = 0;

    #[PositiveOrZero]
    #[Range(max: 59)]
    public int $timeSeconds = 0;

    public null|string $competition = null;

    public function getTimeAsString(): null|string
    {
        if ($this->hasTime() === false) {
            return null;
        }

        $solvingTime = SolvingTime::fromHoursMinutesSeconds(
            $this->timeHours,
            $this->timeMinutes,
            $this->timeSeconds,
        );

        return $solvingTime->toTimeString();
    }

    public function hasTime(): bool
    {
        return $this->timeHours > 0
            || $this->timeMinutes > 0
            || $this->timeSeconds > 0;
    }

    public bool $firstAttempt = false;

    public bool $unboxed = false;

    // Speed Puzzling & Relax common fields
    public null|DateTimeImmutable $finishedAt;

    public null|string $comment = null;

    public null|UploadedFile $finishedPuzzlesPhoto = null;

    // Collection specific
    #[Length(max: 100)]
    public null|string $collection = null;

    #[Length(max: 500)]
    public null|string $collectionDescription = null;

    public CollectionVisibility $collectionVisibility = CollectionVisibility::Private;

    #[Length(max: 500)]
    public null|string $collectionComment = null;

    public function __construct()
    {
        $this->finishedAt = new DateTimeImmutable();
    }

    #[Callback]
    public function validateFinishedAtForSpeed(ExecutionContextInterface $context): void
    {
        if ($this->mode === PuzzleAddMode::SpeedPuzzling && $this->finishedAt === null) {
            $context->buildViolation('finished_at_required_for_speed')
                ->atPath('finishedAt')
                ->addViolation();
        }
    }

    #[Callback]
    public function validatePuzzleEan(ExecutionContextInterface $context): void
    {
        // Only a new puzzle takes the codes - shown exactly then (`hide_new_puzzle`), so a code left
        // hidden in a field (e.g. from a scan) never blocks saving a result of an existing puzzle
        if ($this->addsNewPuzzle()) {
            EanList::addInputViolations($context, 'puzzleEans', $this->puzzleEans, null);
            BrandCodeList::addViolations($context, 'puzzleBrandCodes', $this->puzzleBrandCodes);
        }
    }

    #[Callback]
    public function validateAlternativeNames(ExecutionContextInterface $context): void
    {
        // Like the code: rows left hidden under an existing puzzle never block a save
        if ($this->addsNewPuzzle() && $this->toPuzzleNames()->count() > PuzzleNames::FORM_MAX_NAMES) {
            $context->buildViolation('puzzle_names.too_many_names')
                ->setParameter('%limit%', (string) PuzzleNames::FORM_MAX_NAMES)
                ->atPath('alternativeNames')
                ->addViolation();
        }
    }

    /**
     * The other names of the new puzzle in the order typed - the entity cleans and merges them
     */
    public function toPuzzleNames(): PuzzleNames
    {
        $names = [];

        foreach ($this->alternativeNames as $row) {
            $name = trim($row->name ?? '');

            if ($name !== '') {
                $names[] = new PuzzleName($name, $row->language !== null && $row->language !== '' ? $row->language : null);
            }
        }

        return new PuzzleNames($names);
    }

    private function addsNewPuzzle(): bool
    {
        return $this->puzzle !== null
            && trim($this->puzzle) !== ''
            && Uuid::isValid($this->puzzle) === false
            && $this->brand !== null;
    }
}
