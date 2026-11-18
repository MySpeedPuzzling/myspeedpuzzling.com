<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A standing that cannot be saved as asked (competition toolkit A6) - nothing changed. `reason`: `no_rounds`,
 * `too_many_rounds`, `unknown_round` (not a round of the event), `wrong_category` (a round of another category),
 * `limit_reached` (CompetitionStanding::MAX_PER_COMPETITION), `invalid_name`, `invalid_penalty`; real events L2a:
 * `unknown_division` (not a division of the event), `division_not_in_round` (a round of the standing does not use it).
 */
final class InvalidCompetitionStanding extends ConflictHttpException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('The standing cannot be saved: ' . $reason);
    }

    public function translationKey(): string
    {
        return match ($this->reason) {
            'unknown_division', 'division_not_in_round' => 'standing_division.refused.' . $this->reason,
            default => 'competition_standing.refused.' . $this->reason,
        };
    }
}
