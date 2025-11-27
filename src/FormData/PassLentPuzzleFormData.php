<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class PassLentPuzzleFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public null|string $newHolderCode = null;
}
