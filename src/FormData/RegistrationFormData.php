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

    #[Assert\NotBlank(message: 'auth.email_required')]
    #[Assert\Email(message: 'auth.email_invalid')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[StrongPassword]
    public string $plainPassword = '';

    #[Assert\IsTrue(message: 'registration_terms_required')]
    public bool $termsAccepted = false;

    /**
     * The newsletter's legal basis is consent (privacy policy §10), so the box starts unticked:
     * a pre-ticked box is not consent (GDPR recital 32, CJEU C-673/17 Planet49)
     */
    public bool $newsletter = false;
}
