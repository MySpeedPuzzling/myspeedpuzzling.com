<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * What every event and transaction goes through before it is sent (`before_send`, `before_send_transaction` -
 * config/packages/sentry.php, config/services.php): no personal link's token of an online competition
 * (OnlineTokenSentryScrubber), nothing a player typed into their own fields (OwnFieldsSentryScrubber).
 */
final readonly class SentryBeforeSend
{
    public function __construct(
        private OnlineTokenSentryScrubber $onlineTokenScrubber,
        private OwnFieldsSentryScrubber $ownFieldsScrubber,
    ) {
    }

    /**
     * @return \Closure(Event, null|EventHint): Event
     */
    public function beforeSend(): \Closure
    {
        return fn (Event $event, null|EventHint $hint = null): Event => $this->ownFieldsScrubber->scrub($this->onlineTokenScrubber->scrub($event));
    }
}
