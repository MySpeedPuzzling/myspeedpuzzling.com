<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class ChangeEmailFormData
{
    #[Assert\NotBlank(message: 'auth.email_required')]
    #[Assert\Email(message: 'auth.email_invalid')]
    #[Assert\Length(max: 180)]
    public string $newEmail = '';

    #[Assert\NotBlank(message: 'auth.current_password_required')]
    public string $currentPassword = '';
}
