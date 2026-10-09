<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Move to another series" (move_edition, docs/features/organizations/README.md "Restructuring tools")
 */
final class MoveEditionFormData
{
    public function __construct(
        #[Assert\NotBlank]
        public null|string $seriesId = null,
        // Only needed when the edition's address is taken in the target series - normalised like the "URL" field
        #[Assert\Length(max: 255)]
        public null|string $slug = null,
    ) {
    }
}
