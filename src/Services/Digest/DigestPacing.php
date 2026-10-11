<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Digest;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DigestPeriod;

/**
 * Spreads one run's e-mails evenly over the sending window of the day, Europe/Prague (README §"Pacing and the
 * circuit breaker"): ~12,000 recipients over 07:00-19:00 = one every ~3.6 s, ~1,000 an hour, instead of a burst.
 * The recipients come most engaged first, so the morning goes to the addresses most likely to read us and early
 * trouble shows on the best ones. A run started inside the window spreads over what is left of it, one started after
 * it (a manual re-run in the evening) sends at the slowest allowed pace from now - never two e-mails less than
 * MIN_SPACING_MS apart.
 */
readonly final class DigestPacing
{
    public const string WINDOW_START = '07:00';
    public const string WINDOW_END = '19:00';
    public const int MIN_SPACING_MS = 1000;

    /**
     * @return array{firstDelayMs: int, spacingMs: int}
     */
    public function plan(int $recipients, DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(DigestPeriod::zone());
        [$startHour, $startMinute] = array_map('intval', explode(':', self::WINDOW_START));
        [$endHour, $endMinute] = array_map('intval', explode(':', self::WINDOW_END));
        $windowStart = $local->setTime($startHour, $startMinute);
        $windowEnd = $local->setTime($endHour, $endMinute);

        $sendingFrom = max($local, $windowStart);
        $firstDelayMs = max(0, ($sendingFrom->getTimestamp() - $local->getTimestamp()) * 1000);
        $availableMs = ($windowEnd->getTimestamp() - $sendingFrom->getTimestamp()) * 1000;

        $spacingMs = $recipients > 0 && $availableMs > 0
            ? max(self::MIN_SPACING_MS, intdiv($availableMs, $recipients))
            : self::MIN_SPACING_MS;

        return ['firstDelayMs' => $firstDelayMs, 'spacingMs' => $spacingMs];
    }
}
