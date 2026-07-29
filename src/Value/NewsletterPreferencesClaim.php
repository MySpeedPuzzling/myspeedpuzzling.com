<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class NewsletterPreferencesClaim
{
    public function __construct(
        public NewsletterAudience $audience,
        public string $id,
        public string $email,
    ) {
    }
}
