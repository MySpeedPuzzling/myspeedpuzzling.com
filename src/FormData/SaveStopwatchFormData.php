<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SaveStopwatchFormData
{
    public null|string $puzzleId = null;


    public null|string $comment = null;

    public null|UploadedFile $finishedPuzzlesPhoto = null;

    public null|bool $addPuzzle = null;

    public null|string $puzzleName = null;

    public null|string $puzzleManufacturerId = null;

    public null|string $puzzleManufacturerName = null;

    public null|int $puzzlePiecesCount = null;

    public null|UploadedFile $puzzlePhoto = null;

    public null|string $puzzleEan = null;

    public null|string $puzzleIdentificationNumber = null;

    public null|DateTimeImmutable $finishedAt;

    public function __construct()
    {
        $this->finishedAt = new DateTimeImmutable();
    }
}
