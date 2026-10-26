<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RemindPartnerListings;
use SpeedPuzzling\Web\Message\SendPartnerListingReminder;
use SpeedPuzzling\Web\Query\PartnerFinderEligibility;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * "Still looking?" before the event (docs/features/partner-finder.md "Phase 2 as built"), Jan's rule (2026-10-26): every
 * open listing not reminded yet whose event (one with a finder - PartnerFinderEligibility) starts in
 * LAST_DAYS_BEFORE..REMIND_DAYS_BEFORE days (2-14) and that is at least MIN_LISTING_AGE_DAYS (5) days old - never "the
 * next day", never in the last two days. The first daily run that finds it so reminds it: a listing made 30 days before
 * the event at 14 days, made 10 days before at 5 days, made 6 days before or later never. Each one is marked first (once
 * per listing, whatever the e-mail does), then its e-mail goes async.
 */
#[AsMessageHandler]
readonly final class RemindPartnerListingsHandler
{
    public const int REMIND_DAYS_BEFORE = 14;
    public const int LAST_DAYS_BEFORE = 2;
    public const int MIN_LISTING_AGE_DAYS = 5;

    public function __construct(
        private Connection $database,
        private PartnerListingRepository $partnerListingRepository,
        private PartnerFinderEligibility $partnerFinderEligibility,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int how many listings were reminded
     */
    public function __invoke(RemindPartnerListings $message): int
    {
        $qualifies = PartnerFinderEligibility::SQL_QUALIFIES;
        $days = self::REMIND_DAYS_BEFORE;
        $lastDays = self::LAST_DAYS_BEFORE;
        $minimumAge = self::MIN_LISTING_AGE_DAYS;

        /** @var list<string> $listingIds */
        $listingIds = $this->database->executeQuery(
            <<<SQL
SELECT pl.id
FROM partner_listing pl
INNER JOIN competition c ON c.id = pl.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE pl.closed_at IS NULL
    AND pl.reminded_at IS NULL
    AND {$qualifies}
    AND c.date_from::date >= CAST(:marketplace_today AS date) + {$lastDays}
    AND c.date_from::date <= CAST(:marketplace_today AS date) + {$days}
    AND pl.created_at::date <= CAST(:marketplace_today AS date) - {$minimumAge}
ORDER BY pl.created_at, pl.id
SQL,
            $this->partnerFinderEligibility->todayParameter(),
        )->fetchFirstColumn();

        $now = $this->clock->now();

        foreach ($listingIds as $listingId) {
            $this->partnerListingRepository->get($listingId)->markReminded($now);

            $this->messageBus->dispatch(
                new SendPartnerListingReminder($listingId),
                [new DispatchAfterCurrentBusStamp()],
            );
        }

        return count($listingIds);
    }
}
