<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class EditPuzzleSolvingTimeFormData
{
    public PuzzleAddMode $mode = PuzzleAddMode::SpeedPuzzling;

    #[PositiveOrZero]
    #[Range(max: 999)]
    public int $timeHours = 0;

    #[PositiveOrZero]
    #[Range(max: 59)]
    public int $timeMinutes = 0;

    #[PositiveOrZero]
    #[Range(max: 59)]
    public int $timeSeconds = 0;

    public null|string $comment = null;

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

    public function setTimeFromSeconds(int $totalSeconds): void
    {
        $this->timeHours = intdiv($totalSeconds, 3600);
        $this->timeMinutes = intdiv($totalSeconds % 3600, 60);
        $this->timeSeconds = $totalSeconds % 60;
    }

    public null|UploadedFile $finishedPuzzlesPhoto = null;

    // Not a form field: what the browser read from the photo before it shrank it (FormPhotoStash::browserMetadata(),
    // docs/features/upload-image-metadata.md) - set by the controller after handleRequest()
    public null|UploadedImageMetadata $finishedPuzzlesPhotoBrowserMetadata = null;

    public null|string $brand = null;

    public null|string $puzzle = null;
    public null|string $competition = null;

    public null|DateTimeImmutable $finishedAt;

    public bool $firstAttempt = false;

    public bool $unboxed = false;

    /**
     * @param DateTimeImmutable $finishedAt the default day - the clock's now, passed in (a form data object reads no clock)
     */
    public function __construct(DateTimeImmutable $finishedAt)
    {
        $this->finishedAt = $finishedAt;
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
}
