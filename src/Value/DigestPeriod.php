<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One weekly digest period: ISO week key ("2026-W28") plus its window boundaries.
 * The staleness TTL (README §6) stops yesterday's digest from arriving tomorrow.
 */
final readonly class DigestPeriod
{
    private const string STALE_AFTER_PERIOD_END = '+3 days';

    private function __construct(
        public string $key,
        public DateTimeImmutable $weekStart,
        public DateTimeImmutable $weekEnd,
    ) {
    }

    public static function weeklyFor(DateTimeImmutable $moment): self
    {
        $weekStart = $moment
            ->setISODate((int) $moment->format('o'), (int) $moment->format('W'))
            ->setTime(0, 0);

        return new self(
            key: $moment->format('o-\WW'),
            weekStart: $weekStart,
            weekEnd: $weekStart->modify('+7 days'),
        );
    }

    public static function fromKey(string $periodKey): self
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $periodKey, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid weekly period key "%s".', $periodKey));
        }

        $weekStart = (new DateTimeImmutable('now'))
            ->setISODate((int) $matches[1], (int) $matches[2])
            ->setTime(0, 0);

        return new self(
            key: $periodKey,
            weekStart: $weekStart,
            weekEnd: $weekStart->modify('+7 days'),
        );
    }

    /**
     * The digest for a PAST week goes out right after the week ends; once the TTL passes,
     * a delayed message is silently dropped instead of delivering ancient news.
     */
    public function isStaleAt(DateTimeImmutable $now): bool
    {
        return $now > $this->weekEnd->modify(self::STALE_AFTER_PERIOD_END);
    }
}
