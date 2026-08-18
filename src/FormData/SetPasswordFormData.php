<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Validator\StrongPassword;

final class SetPasswordFormData
{
    #[StrongPassword]
    public string $plainPassword = '';
}
