<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeFormCheck;

/**
 * "Yes, it's right" for the time of an add/edit form a test submits (docs/features/suspicious-time-review.md, "Catch it
 * while typing"): the key of exactly the values sent - the puzzle, the time, the day and the number of people. Tests
 * whose subject is something else answer the pace check up front; the check itself has its own tests.
 */
trait AnswersPaceCheck
{
    /**
     * @param array<string, mixed> $formFields the add/edit form's fields (puzzle, timeHours/-Minutes/-Seconds, finishedAt)
     * @param list<string> $groupPlayers
     */
    private static function paceConfirmationFor(array $formFields, array $groupPlayers = []): string
    {
        $value = static fn (string $name): string => is_scalar($formFields[$name] ?? null) ? (string) $formFields[$name] : '';
        $day = DateTimeImmutable::createFromFormat('!d.m.Y', $value('finishedAt'));

        return SuspiciousTimeFormCheck::confirmationKey(
            $value('puzzle'),
            (int) $value('timeHours') * 3600 + (int) $value('timeMinutes') * 60 + (int) $value('timeSeconds'),
            $day !== false ? $day : null,
            SuspiciousTimeFormCheck::puzzlersCount($groupPlayers, null),
        );
    }
}
