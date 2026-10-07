<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A change refused because it would make official results disappear as a side effect - taking a person with a result
 * out of a round, deleting a pair/team with a result, removing a person with a result from the event, changing the
 * category of a round with results (docs/features/competitions-management/official-results.md, guards).
 * `reason` is a key of official_results.guard.* (the message shown to the organiser). Nothing was changed.
 */
final class OfficialResultsProtected extends ConflictHttpException
{
    public const string ENTRY_HAS_RESULT = 'entry_has_result';
    public const string TEAM_HAS_RESULT = 'team_has_result';
    public const string PARTICIPANT_HAS_RESULT = 'participant_has_result';
    public const string ROUND_CATEGORY_LOCKED = 'round_category_locked';

    public function __construct(
        readonly public string $reason,
    ) {
        parent::__construct(sprintf('Refused - official results would be lost (%s).', $reason));
    }

    public function translationKey(): string
    {
        return 'official_results.guard.' . $this->reason;
    }
}
