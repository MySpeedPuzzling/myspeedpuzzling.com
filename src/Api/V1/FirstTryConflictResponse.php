<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Results\FirstTryTime;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A 422 for firstAttempt=true when somebody of the result already has a first try of the puzzle
 * (docs/features/first-try-integrity.md). Names only the caller's own results - a co-puzzler's history stays theirs.
 */
final readonly class FirstTryConflictResponse
{
    public static function from(FirstTryAlreadyTaken $exception, string $playerId): UnprocessableEntityHttpException
    {
        $own = array_values(array_map(
            static fn(FirstTryTime $time): string => $time->timeId,
            array_filter($exception->assessment->holds, static fn(FirstTryTime $time): bool => $time->involves($playerId)),
        ));

        if (count($own) === count($exception->assessment->holds)) {
            $message = sprintf(
                'A first attempt of this puzzle is already recorded for you (result %s). Send firstAttempt=false, or remove the flag from that result first.',
                implode(', ', $own),
            );
        } else {
            $message = 'A first attempt of this puzzle is already recorded for a co-puzzler. Send firstAttempt=false.';
        }

        return new UnprocessableEntityHttpException($message, $exception);
    }
}
