<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class ClaimVoucherFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 32)]
    public string $code = '';
}
