<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Regex;

final class EditPuzzleSolvingTimeFormData
{
    #[Regex(PuzzlingTimeFormatter::TIME_FORMAT, 'Prosím zadejte čas ve formátu HH:MM:SS')]
    public null|string $time = null;

    public null|string $comment = null;

    public null|DateTimeImmutable $finishedAt = null;

    public null|UploadedFile $finishedPuzzlesPhoto = null;
}
