<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Validator\StrongPassword;
use Symfony\Component\Validator\Constraints as Assert;

final class ChangePasswordFormData
{
    #[Assert\NotBlank]
    public string $currentPassword = '';

    #[StrongPassword]
    public string $newPassword = '';
}
