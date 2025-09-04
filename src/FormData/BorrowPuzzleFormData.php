<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class BorrowPuzzleFormData
{
    #[Assert\NotBlank(message: 'Please enter the person\'s name or select a player')]
    #[Assert\Length(min: 1, max: 255)]
    public null|string $person = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['to', 'from'])]
    public string $borrowingType = 'to';
}
