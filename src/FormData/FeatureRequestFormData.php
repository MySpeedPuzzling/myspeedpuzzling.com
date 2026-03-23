<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class FeatureRequestFormData
{
    #[NotBlank]
    #[Length(max: 255)]
    public string $title = '';

    #[NotBlank]
    #[Length(max: 2000)]
    public string $description = '';
}
