<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

final class EditionFormData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 250)]
        public null|string $name = null,
        #[Assert\NotNull]
        public null|DateTimeImmutable $dateFrom = null,
        #[Assert\NotNull]
        public null|DateTimeImmutable $dateTo = null,
        #[Assert\Url]
        #[Assert\Length(max: 250)]
        public null|string $registrationLink = null,
        #[Assert\Url]
        #[Assert\Length(max: 250)]
        public null|string $resultsLink = null,
        // The edition's own website - the "Info" button on the edition page
        #[Assert\Url]
        #[Assert\Length(max: 250)]
        public null|string $link = null,
        public null|string $description = null,
        // "Who can enter" - empty shows the series' (docs/features/organizations/README.md)
        #[Assert\Length(max: 120)]
        public null|string $eligibility = null,
    ) {
    }
}
