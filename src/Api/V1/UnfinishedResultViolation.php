<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Validator\Exception\ValidationException;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * A refused unfinished result (`pieces_placed` on `POST` / `PUT /me/solving-times`,
 * docs/features/competitions-management/unfinished-results-plan.md step 4) as the API's 422 - a violation on the field
 * at fault, the same rule the website applies (UnfinishedResult). Nothing was saved.
 */
final class UnfinishedResultViolation
{
    public static function from(null|\Throwable $refusal): null|ValidationException
    {
        return match (true) {
            $refusal instanceof UnfinishedResultNeedsTimeLimit => self::violation(
                'pieces_placed',
                'pieces_placed needs a competition round with a time limit for this puzzle and solo/duo/team - name its event with round_id, competition_id or series_id (a series counts when its edition is found by the puzzle).',
            ),
            $refusal instanceof InvalidPiecesPlaced => self::violation(
                'pieces_placed',
                $refusal->allPlaced()
                    ? sprintf('All %d pieces placed is a finished result - send time without pieces_placed.', $refusal->puzzlePiecesCount)
                    : sprintf('pieces_placed must be between 1 and %d (fewer than the puzzle\'s %d pieces).', max(1, $refusal->puzzlePiecesCount - 1), $refusal->puzzlePiecesCount),
            ),
            $refusal instanceof UnfinishedTotalTimeWithinLimit => self::violation(
                'time',
                sprintf('With pieces_placed, time is the total time when they finished afterwards and must be over the round\'s %d min limit - leave it out if they stopped when the time ran out.', $refusal->minutesLimit),
            ),
            default => null,
        };
    }

    private static function violation(string $field, string $message): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, null, [], null, $field, null),
        ]));
    }
}
