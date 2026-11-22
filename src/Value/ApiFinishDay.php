<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * API v1 `finished_at` on POST/PUT /me/solving-times (docs/features/time-and-clock/STATUS.md, Jan's answer 6): the
 * solve's calendar day, as the client wrote it. A result's `finished_at` is a day everywhere (the add-result form stores
 * the picked day at midnight UTC), so the date part is taken in the input's own offset and stored at midnight UTC like
 * the form - `2026-02-02T23:59:59-06:00` is 2 February, never the 3rd it is in UTC. The time of day is not kept.
 * Accepts a date alone (`2026-02-02`) too.
 */
final readonly class ApiFinishDay
{
    /**
     * Null for a string that is no date.
     */
    public static function fromInput(string $input): null|DateTimeImmutable
    {
        try {
            $parsed = new DateTimeImmutable($input, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return new DateTimeImmutable($parsed->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));
    }
}
