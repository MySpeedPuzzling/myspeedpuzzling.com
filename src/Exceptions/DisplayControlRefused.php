<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A live control write refused (competition toolkit v2 L1/L2, displays-standings.md §5.1) - nothing changed. An
 * HttpException, so it reaches the controller as itself through the command bus; the endpoints answer 422
 * `{error: reason}`.
 */
final class DisplayControlRefused extends UnprocessableEntityHttpException
{
    /** The event has no display - nothing would show it */
    public const string NO_DISPLAYS = 'no_displays';
    /** The message is longer than CompetitionDisplayControl::MESSAGE_MAX_LENGTH characters */
    public const string TOO_LONG = 'too_long';
    /** The spotlight's entry is not an entry of that round (another round's, a removed participant, unknown) */
    public const string UNKNOWN_ENTRY = 'unknown_entry';

    public function __construct(
        readonly public string $reason,
    ) {
        parent::__construct(match ($reason) {
            self::NO_DISPLAYS => 'The event has no display. Nothing was changed.',
            self::TOO_LONG => 'The message is too long. Nothing was changed.',
            default => 'This entry is not in the round. Nothing was changed.',
        });
    }
}
