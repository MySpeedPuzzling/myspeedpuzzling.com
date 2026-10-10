<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * The weekly "We miss you" run (docs/features/content-digest/README.md §"We miss you"). A run is keyed by its day
 * ("miss-you-2026-10-20"); the e-mail of one player by the inactive spell it is about - the moment of their last
 * logged result ("miss-you:2026-09-14T18:02:11"), so the log's unique (player, period_key) makes it once per spell.
 */
final readonly class MissYouRun
{
    /** A Tuesday - the week after the launch newsletter. No run before it (a guard in code, not only a cron date). */
    public const string FIRST_DAY = '2026-10-20';

    /** A queued e-mail later than this many days after its run day is dropped, never delivered late. */
    public const int STALE_AFTER_DAYS = 2;

    private const string RUN_KEY_PREFIX = 'miss-you-';

    public static function runKey(DateTimeImmutable $now): string
    {
        return self::RUN_KEY_PREFIX . DigestPeriod::midnightOf($now)->format('Y-m-d');
    }

    public static function spellKey(DateTimeImmutable $lastLoggedAt): string
    {
        return 'miss-you:' . $lastLoggedAt->format('Y-m-d\TH:i:s');
    }

    public static function isAllowedOn(DateTimeImmutable $now): bool
    {
        return DigestPeriod::midnightOf($now) >= new DateTimeImmutable(self::FIRST_DAY . ' 00:00:00', DigestPeriod::zone());
    }

    /**
     * False for a key that is no run key, a run before FIRST_DAY, or a run whose sending days are over.
     */
    public static function isSendableAt(string $runKey, DateTimeImmutable $now): bool
    {
        if (preg_match('/^' . self::RUN_KEY_PREFIX . '(\d{4}-\d{2}-\d{2})$/', $runKey, $matches) !== 1) {
            return false;
        }

        $runDay = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1], DigestPeriod::zone());

        return $runDay !== false
            && self::isAllowedOn($runDay)
            && $now < $runDay->modify(sprintf('+%d days', self::STALE_AFTER_DAYS));
    }
}
