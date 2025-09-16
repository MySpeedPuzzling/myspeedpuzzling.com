<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

final class PuzzleSolvingTimeFormData
{
    #[Regex(PuzzlingTimeFormatter::TIME_FORMAT, 'puzzling_time_format')]
    public null|string $time = null;

    public null|string $comment = null;

    public null|UploadedFile $finishedPuzzlesPhoto = null;

    public null|string $brand = null;

    public null|string $puzzle = null;
    public null|string $competition = null;

    #[Positive]
    #[Range(min: 10, max: 25000)]
    public null|int $puzzlePiecesCount = null;

    public null|UploadedFile $puzzlePhoto = null;

    #[Length(max: 15)]
    public null|string $puzzleEan = null;

    #[Length(max: 50)]
    public null|string $puzzleIdentificationNumber = null;

    public null|DateTimeImmutable $finishedAt;

    public bool $firstAttempt = false;

    public function __construct()
    {
        $this->finishedAt = new DateTimeImmutable();
    }
}
