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
        // Required - checked by the controller with its own message (event_duplicate.form.date_required)
        public null|DateTimeImmutable $dateFrom = null,
    ) {
    }
}
