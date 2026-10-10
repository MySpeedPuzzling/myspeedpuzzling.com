<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Exceptions\InvalidDigestPeriod;

/**
 * One period of the activity digest and of the "Your week" page (docs/features/content-digest/README.md §"Frequency
 * and periods"). Periods are Europe/Prague calendar periods - the site's home zone, the zone the Monday cron runs in:
 *
 *  - a week = an ISO week, Monday 00:00 to the next Monday 00:00, key "2026-W42";
 *  - two weeks = two ISO weeks in a fixed rhythm anchored on Monday 12 October 2026 (FIRST_PERIOD_START): every
 *    even number of weeks from the anchor starts one, key "2026-W42-W43" (across a new year "2026-W53-W01");
 *  - a month = a calendar month, key "2026-10".
 *
 * The e-mail about a period goes out on its send day: the Monday it ends on (a week, two weeks), or the first Monday
 * on or after the month's end (a month - the 1st when that is a Monday). The no-activity e-mail goes the day after.
 * Nothing goes out about a period that starts before FIRST_PERIOD_START (the launch pause: the first digest is
 * Monday 19 October 2026 about 12-18 October) and nothing once it is stale (STALE_AFTER_DAYS after the send day).
 */
final readonly class DigestPeriod
{
    public const string TIMEZONE = 'Europe/Prague';

    /**
     * A Monday. No digest is ever sent about a period starting earlier (the pause until the week after the launch
     * newsletter) and the two-week rhythm counts from it.
     */
    public const string FIRST_PERIOD_START = '2026-10-12';

    /** A delayed or retried e-mail older than this many days after its send day is dropped, never delivered late. */
    public const int STALE_AFTER_DAYS = 3;

    private function __construct(
        public DigestPeriodLength $length,
        public string $key,
        /** Europe/Prague midnight, inclusive */
        public DateTimeImmutable $start,
        /** Europe/Prague midnight, exclusive */
        public DateTimeImmutable $end,
    ) {
    }

    public static function containing(DigestPeriodLength $length, DateTimeImmutable $moment): self
    {
        $local = $moment->setTimezone(self::zone());

        return match ($length) {
            DigestPeriodLength::Week => self::weekStartingOn(self::mondayOf($local)),
            DigestPeriodLength::TwoWeeks => self::twoWeeksContaining($local),
            DigestPeriodLength::Month => self::monthStartingOn($local->modify('first day of this month')->setTime(0, 0)),
        };
    }

    /**
     * The latest period of this length whose send day is today or earlier - what a run today is about. The caller
     * still checks isBeforeFirstPeriod() and isStaleAt(): on a Monday that is not a send day (two weeks, a month)
     * the period found is days old, so stale.
     */
    public static function latestSentBy(DigestPeriodLength $length, DateTimeImmutable $now): self
    {
        $candidate = self::containing($length, $now)->previous();

        if ($candidate->sendDay() > self::midnightOf($now)) {
            $candidate = $candidate->previous();
        }

        return $candidate;
    }

    /**
     * @throws InvalidDigestPeriod
     */
    public static function fromKey(string $key): self
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $key, $matches) === 1) {
            $candidate = self::containing(DigestPeriodLength::Week, self::isoMonday((int) $matches[1], (int) $matches[2]));
        } elseif (preg_match('/^(\d{4})-W(\d{2})-W\d{2}$/', $key, $matches) === 1) {
            $candidate = self::containing(DigestPeriodLength::TwoWeeks, self::isoMonday((int) $matches[1], (int) $matches[2]));
        } elseif (preg_match('/^(\d{4})-(\d{2})$/', $key, $matches) === 1) {
            if (checkdate((int) $matches[2], 1, (int) $matches[1]) === false) {
                throw new InvalidDigestPeriod($key);
            }

            $candidate = self::monthStartingOn(new DateTimeImmutable(sprintf('%s-%s-01 00:00:00', $matches[1], $matches[2]), self::zone()));
        } else {
            throw new InvalidDigestPeriod($key);
        }

        // Week 54, week 00, a two-week key off the rhythm, a second week that does not follow the first: the
        // canonical key of the period the numbers point into differs - one key per period, so URLs never alias
        $year = (int) $candidate->start->format('Y');

        if ($candidate->key !== $key || $year < 2000 || $year > 2100) {
            throw new InvalidDigestPeriod($key);
        }

        return $candidate;
    }

    public function previous(): self
    {
        return self::containing($this->length, $this->start->modify('-1 day'));
    }

    public function next(): self
    {
        return self::containing($this->length, $this->end);
    }

    /**
     * The Monday the activity digest about this period is sent (Europe/Prague midnight).
     */
    public function sendDay(): DateTimeImmutable
    {
        if ($this->length !== DigestPeriodLength::Month || $this->end->format('N') === '1') {
            return $this->end;
        }

        return $this->end->modify('next monday')->setTime(0, 0);
    }

    /**
     * The day the no-activity e-mail about this period is sent - the day after the send day.
     */
    public function noActivityDay(): DateTimeImmutable
    {
        return $this->sendDay()->modify('+1 day');
    }

    public function isBeforeFirstPeriod(): bool
    {
        return $this->start < self::firstPeriodStart();
    }

    public function isStaleAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->sendDay()->modify(sprintf('+%d days', self::STALE_AFTER_DAYS));
    }

    public function hasEndedAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->end;
    }

    public function hasStartedAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->start;
    }

    public function lastDay(): DateTimeImmutable
    {
        return $this->end->modify('-1 day');
    }

    /**
     * Start as a UTC timestamp string - the database stores times in UTC without a zone.
     */
    public function startUtc(): string
    {
        return $this->start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function endUtc(): string
    {
        return $this->end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function firstPeriodStart(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::FIRST_PERIOD_START . ' 00:00:00', self::zone());
    }

    public static function midnightOf(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimezone(self::zone())->setTime(0, 0);
    }

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }

    private static function weekStartingOn(DateTimeImmutable $monday): self
    {
        return new self(
            length: DigestPeriodLength::Week,
            key: $monday->format('o-\WW'),
            start: $monday,
            end: $monday->modify('+7 days'),
        );
    }

    private static function twoWeeksContaining(DateTimeImmutable $local): self
    {
        $anchor = self::firstPeriodStart();
        $weeksFromAnchor = intdiv(self::daysBetween($anchor, self::mondayOf($local)), 7);
        // Floor to an even number of weeks, before the anchor too (-1 → -2, -2 → -2)
        $periodWeeks = $weeksFromAnchor - (($weeksFromAnchor % 2) + 2) % 2;
        $start = $anchor->modify(sprintf('%+d days', $periodWeeks * 7));
        $secondWeek = $start->modify('+7 days');

        return new self(
            length: DigestPeriodLength::TwoWeeks,
            key: $start->format('o-\WW') . '-' . $secondWeek->format('\WW'),
            start: $start,
            end: $start->modify('+14 days'),
        );
    }

    private static function monthStartingOn(DateTimeImmutable $firstDay): self
    {
        return new self(
            length: DigestPeriodLength::Month,
            key: $firstDay->format('Y-m'),
            start: $firstDay,
            end: $firstDay->modify('first day of next month'),
        );
    }

    private static function mondayOf(DateTimeImmutable $local): DateTimeImmutable
    {
        return $local->setISODate((int) $local->format('o'), (int) $local->format('W'), 1)->setTime(0, 0);
    }

    private static function isoMonday(int $year, int $week): DateTimeImmutable
    {
        return (new DateTimeImmutable('2000-01-03 00:00:00', self::zone()))->setISODate($year, $week, 1)->setTime(0, 0);
    }

    /**
     * Whole calendar days from one date to another - by the dates, so a DST change between them does not matter.
     */
    private static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $utc = new DateTimeZone('UTC');
        $fromNoon = new DateTimeImmutable($from->format('Y-m-d') . ' 12:00:00', $utc);
        $toNoon = new DateTimeImmutable($to->format('Y-m-d') . ' 12:00:00', $utc);

        return intdiv($toNoon->getTimestamp() - $fromNoon->getTimestamp(), 86400);
    }
}
