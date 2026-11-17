<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An advancement step that cannot be saved as asked (competition toolkit A8, AdvancementStepGraph) - nothing changed.
 * `reason`: `no_sources`, `no_targets`, `too_many_rounds`, `unknown_round` (not a round of the event), `source_is_target`,
 * `mixed_categories`, `single_needs_one_target`, `balanced_needs_two_targets`, `by_source_incomplete`,
 * `invalid_qualify_count`, `invalid_country_count`, `round_fed_twice` (a round is the target of another step already),
 * `cycle`, `limit_reached`.
 */
final class InvalidAdvancementStep extends ConflictHttpException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('The advancement step cannot be saved: ' . $reason);
    }

    public function translationKey(): string
    {
        return 'advancement_step.refused.' . $this->reason;
    }
}
