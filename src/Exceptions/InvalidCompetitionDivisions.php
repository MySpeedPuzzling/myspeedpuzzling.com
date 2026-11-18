<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * An event's divisions that cannot be saved as asked (shared-puzzles-and-divisions.md D1) - nothing changed. `reason`:
 * `too_many` (CompetitionDivision::MAX_PER_COMPETITION), `invalid_name` (empty or too long), `invalid_short_name` (too
 * long), `duplicate_name` (two divisions of the event with one name, case does not matter), `duplicate_id`,
 * `unknown_division` (an id of another event's division, or a round naming a division not in the list),
 * `unknown_round` (not a round of the event), `unknown_entry` (no such entry in the round, or one of a removed participant), `duplicate_entry` (one entry listed
 * twice), `not_in_round` (a division
 * the round does not use), `used_by_standing` (real events L2a: a standing is limited to the division - removing it, or
 * removing it from a round the standing counts, waits until that standing is changed; subject = the standing's name). `subject` names what it is about (a name, an id) when known.
 */
final class InvalidCompetitionDivisions extends UnprocessableEntityHttpException implements InternalApiErrorCode
{
    public function __construct(
        public readonly string $reason,
        public readonly null|string $subject = null,
    ) {
        parent::__construct('The divisions cannot be saved: ' . $reason . ($subject !== null ? ' (' . $subject . ')' : ''));
    }

    public function translationKey(): string
    {
        return $this->reason === 'used_by_standing'
            ? 'standing_division.refused.used_by_standing'
            : 'competition_toolkit.divisions.refused.' . $this->reason;
    }

    public function internalApiErrorCode(): string
    {
        return $this->reason;
    }
}
