<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Regex;

final class AddPuzzleSolvingTimeFormData
{
    public null|string $puzzleId = null;

    #[Regex('/^([0-9]{1,2}):([0-5][0-9]):([0-5][0-9])$/', 'Prosím zadejte čas ve formátu HH:MM:SS')]
    public null|string $time = null;

    #[NotNull]
    public null|int $playersCount = null;

    public null|string $comment = null;

    public null|string $solvedPuzzlesPhoto = null;
}
