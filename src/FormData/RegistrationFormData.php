<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Validator\StrongPassword;
use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationFormData
{
    /**
     * Optional: the name others see next to the times. Asked here because a player who
     * logs a time before visiting their profile would otherwise rank as a bare #CODE.
     */
    #[Assert\Length(max: 100)]
    public null|string $name = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[StrongPassword]
    public string $plainPassword = '';
}
