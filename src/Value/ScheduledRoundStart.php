<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;

/**
 * The moment a scheduled round start asks for (ScheduleRoundStartController, docs/features/online-competitions/README.md
 * §5.1) - always a whole second, as its UTC moment: the stopwatch column keeps whole seconds, and a start on a whole
 * second is exact on every device.
 */
final class ScheduledRoundStart
{
    // "Start in N s" - from a second to an hour ahead
    public const int MAX_COUNTDOWN_SECONDS = 3600;

    private const string OFFSET_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{3})?)?(Z|[+-]\d{2}:?\d{2})$/';
    private const string LOCAL_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/';

    /**
     * "Start in N s": the next whole second at least N seconds ahead - a 10 s countdown lasts 10 to 11 s.
     *
     * @throws RoundStopwatchRefused
     */
    public static function inSeconds(string $seconds, DateTimeImmutable $now): DateTimeImmutable
    {
        if (preg_match('/^\d{1,4}$/', $seconds) !== 1 || (int) $seconds < 1 || (int) $seconds > self::MAX_COUNTDOWN_SECONDS) {
            throw new RoundStopwatchRefused(RoundStopwatchRefusal::InvalidStart);
        }

        $whole = $now->getTimestamp() + (int) $seconds + ((int) $now->format('u') > 0 ? 1 : 0);

        return new DateTimeImmutable('@' . $whole);
    }

    /**
     * "Start at …": a moment with its offset ("2026-11-10T19:00:00+01:00", "…Z", JavaScript's toISOString()), or a wall
     * time of the round's zone as a datetime-local input sends it ("2026-11-10T19:00", seconds optional) - refused when
     * that wall time does not exist there (skipped or repeated by a daylight-saving change). A fraction of a second
     * counts as the next whole second - never earlier than asked for.
     *
     * @throws RoundStopwatchRefused
     */
    public static function at(string $value, string $timezone): DateTimeImmutable
    {
        $value = trim($value);

        if (preg_match(self::OFFSET_PATTERN, $value, $match) === 1) {
            $format = match (true) {
                $match[2] !== '' => 'Y-m-d\TH:i:s.vP',
                $match[1] !== '' => 'Y-m-d\TH:i:sP',
                default => 'Y-m-d\TH:iP',
            };
            $moment = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($moment === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new RoundStopwatchRefused(RoundStopwatchRefusal::InvalidStart);
            }

            $whole = $moment->getTimestamp() + ((int) $moment->format('u') > 0 ? 1 : 0);

            return new DateTimeImmutable('@' . $whole);
        }

        if (preg_match(self::LOCAL_PATTERN, $value, $match) === 1) {
            try {
                $moment = RoundTimezone::parseLocal($value, ($match[1] ?? '') !== '' ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i', $timezone);
            } catch (InvalidLocalTime) {
                throw new RoundStopwatchRefused(RoundStopwatchRefusal::InvalidStart);
            }

            return $moment->setTimezone(new DateTimeZone('UTC'));
        }

        throw new RoundStopwatchRefused(RoundStopwatchRefusal::InvalidStart);
    }
}
