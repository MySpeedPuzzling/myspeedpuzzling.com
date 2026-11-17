<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Applies every waiting event link (WaitingEventLink) whose event or series is publicly visible now, and drops the ones
 * whose event or series was rejected - the 15-minute reconcile cron (myspeedpuzzling:reconcile-round-results). The
 * handler answers ['applied' => int, 'dropped' => int].
 */
readonly final class ApplyWaitingEventLinks
{
}
