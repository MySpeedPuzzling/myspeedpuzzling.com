<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Exceptions\InsightsSnapshotMonthNotAllowed;
use SpeedPuzzling\Web\Value\InsightsPeriod;

/**
 * What a monthly snapshot is taken over: a member's view of the last 12 months, no other filter.
 */
final class InsightsSnapshotCriteria
{
    public const InsightsPeriod PERIOD = InsightsPeriod::Last12Months;

    /** Players with fewer solo results in the period get no snapshot (nothing to compare yet) */
    public const int MIN_RESULTS = 10;

    /** A run in the first days of a month may still store the month that just ended (a late or re-run cron) */
    public const int PREVIOUS_MONTH_GRACE_DAYS = 3;

    /**
     * The first day of the month a run stores its snapshots as: the current month by default; `Y-m` only for the
     * current month, or the previous one within its grace days - a snapshot is today's data and must never be labelled
     * as an older (or a future) month.
     *
     * @throws InsightsSnapshotMonthNotAllowed
     */
    public static function month(null|string $month, DateTimeImmutable $now): DateTimeImmutable
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $current = new DateTimeImmutable($now->format('Y-m-01'), new DateTimeZone('UTC'));

        if ($month === null || $month === $current->format('Y-m')) {
            return $current;
        }

        $previous = $current->modify('-1 month');

        if ($month === $previous->format('Y-m') && (int) $now->format('j') <= self::PREVIOUS_MONTH_GRACE_DAYS) {
            return $previous;
        }

        throw new InsightsSnapshotMonthNotAllowed(sprintf(
            'A snapshot holds today\'s data: store it as %s%s, not as %s.',
            $current->format('Y-m'),
            (int) $now->format('j') <= self::PREVIOUS_MONTH_GRACE_DAYS ? ' or ' . $previous->format('Y-m') : '',
            $month,
        ));
    }

    public static function criteria(): InsightsCriteria
    {
        return InsightsCriteria::fromUserInput(['period' => self::PERIOD->value], isMember: true);
    }
}
