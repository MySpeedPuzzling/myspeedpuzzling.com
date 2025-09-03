<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class PuzzleCollectionFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 255)]
    public null|string $name = null;

    #[Assert\Length(max: 1000)]
    public null|string $description = null;

    public bool $isPublic = true;
}