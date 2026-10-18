<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

readonly final class RegisterUser
{
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public string $plainPassword,
        public null|string $locale,
        public null|string $name = null,
        // Ticked "send me the newsletter" box - off unless the person asked for it
        public bool $newsletter = false,
        // Ticked "I agree to the Terms of service and the Privacy policy" (required by the forms)
        public bool $termsAccepted = false,
        // What the browser reported in the form's hidden field (docs/features/player-timezone.md) - stored only when listed
        public null|string $timezone = null,
    ) {
    }
}
