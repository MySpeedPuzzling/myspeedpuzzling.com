<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\CloseFinishedPartnerListings;
use SpeedPuzzling\Web\Query\PartnerFinderEligibility;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stale listings are the death of every partner board (docs/features/partner-finder.md D7): an open listing closes as
 * `event_over` once its event's last day has passed - the date rule of PartnerFinderEligibility::SQL_QUALIFIES /
 * GetMarketplaceEvents::SQL_QUALIFIES, so a listing closes exactly when its event drops out of the finder - and as
 * `entered` once its player is in a pair/team entry of the event (PartnerFinderEligibility::sqlPlayerEntered()).
 *
 * Two set-based UPDATEs on purpose - a genuine bulk operation: every listing of every finished event at once, rows
 * nobody needs loaded one by one; the columns written are exactly what PartnerListing::close() writes, and only open
 * listings are touched, so the first reason always stays.
 */
#[AsMessageHandler]
readonly final class CloseFinishedPartnerListingsHandler
{
    public function __construct(
        private Connection $database,
        private PartnerFinderEligibility $partnerFinderEligibility,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{eventOver: int, entered: int} How many listings were closed
     */
    public function __invoke(CloseFinishedPartnerListings $message): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $eventOver = $this->database->executeStatement(
            <<<SQL
UPDATE partner_listing pl
SET closed_at = :now, closed_reason = :reason, updated_at = :now
FROM competition c
WHERE c.id = pl.competition_id
    AND pl.closed_at IS NULL
    AND c.date_from IS NOT NULL
    AND COALESCE(c.date_to, c.date_from)::date < CAST(:marketplace_today AS date)
SQL,
            [
                'now' => $now,
                'reason' => PartnerListingClosedReason::EventOver->value,
                ...$this->partnerFinderEligibility->todayParameter(),
            ],
        );

        $entered = PartnerFinderEligibility::sqlPlayerEntered('pl.competition_id', 'pl.player_id');

        $enteredCount = $this->database->executeStatement(
            <<<SQL
UPDATE partner_listing pl
SET closed_at = :now, closed_reason = :reason, updated_at = :now
WHERE pl.closed_at IS NULL
    AND {$entered}
SQL,
            [
                'now' => $now,
                'reason' => PartnerListingClosedReason::Entered->value,
            ],
        );

        return [
            'eventOver' => (int) $eventOver,
            'entered' => (int) $enteredCount,
        ];
    }
}
