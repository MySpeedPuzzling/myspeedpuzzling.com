<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Regex;

final class SaveStopwatchFormData
{
    public null|string $puzzleId = null;

    #[NotNull]
    public null|int $playersCount = null;

    public null|string $comment = null;

    public null|UploadedFile $solvedPuzzlesPhoto = null;

    public null|bool $addPuzzle = null;

    public null|string $puzzleName = null;

    public null|string $puzzleManufacturerId = null;

    public null|string $puzzleManufacturerName = null;

    public null|int $puzzlePiecesCount = null;
}
