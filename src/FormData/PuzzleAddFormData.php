<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;

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

    #[Length(max: 15)]
    public null|string $puzzleEan = null;

    #[Length(max: 50)]
    public null|string $puzzleIdentificationNumber = null;

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
}
