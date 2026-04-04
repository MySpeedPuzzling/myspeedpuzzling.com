<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

final class EditionFormData
{
    public function __construct(
        #[Assert\NotBlank]
        public null|string $name = null,
        #[Assert\NotNull]
        public null|DateTimeImmutable $startsAt = null,
        #[Assert\NotNull]
        #[Assert\Positive]
        public null|int $minutesLimit = null,
        #[Assert\Url]
        public null|string $registrationLink = null,
        #[Assert\Url]
        public null|string $resultsLink = null,
    ) {
    }
}
