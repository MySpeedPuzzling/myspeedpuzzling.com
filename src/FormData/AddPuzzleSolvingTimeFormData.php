<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints\Regex;

final class AddPuzzleSolvingTimeFormData
{
    public string $puzzleId = '';

    #[Regex('/^([0-9]{1,2}):([0-5][0-9]):([0-5][0-9])$/', 'Prosím zadejte čas ve formátu HH:MM:SS')]
    public null|string $time = null;

    public int $playersCount = 1;

    public null|string $comment = null;

    public null|string $solvedPuzzlesPhoto = null;
}
