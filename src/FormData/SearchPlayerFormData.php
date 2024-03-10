<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints\Length;

final class SearchPlayerFormData
{
    #[Length(min: 3)]
    public string $search = '';
}
