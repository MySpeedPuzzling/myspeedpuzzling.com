<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Move to another event or edition" (move_competition_round, docs/features/organizations/README.md "Restructuring
 * tools")
 */
final class MoveRoundFormData
{
    public function __construct(
        #[Assert\NotBlank]
        public null|string $competitionId = null,
    ) {
    }
}
