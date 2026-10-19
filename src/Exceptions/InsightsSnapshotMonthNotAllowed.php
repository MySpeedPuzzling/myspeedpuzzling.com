<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A monthly Insights snapshot is today's data: it may be stored as the current month, or as the previous one while
 * that month ended at most InsightsSnapshotCriteria::PREVIOUS_MONTH_GRACE_DAYS ago (a late cron run) - never as an
 * older or a future month.
 */
final class InsightsSnapshotMonthNotAllowed extends \InvalidArgumentException
{
}
