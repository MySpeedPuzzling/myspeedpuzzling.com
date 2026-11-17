<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Duplicate" (DuplicateCompetitionController): the copy's first day and name - everything else is copied
 */
final class DuplicateCompetitionFormData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 250)]
        public null|string $name = null,
        #[Assert\NotNull]
        public null|DateTimeImmutable $dateFrom = null,
    ) {
    }
}
