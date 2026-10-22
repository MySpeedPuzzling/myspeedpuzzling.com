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
 * "Still looking?" two weeks before the event (docs/features/partner-finder.md "Phase 2 as built"): every open listing
 * not reminded yet whose event (one with a finder - PartnerFinderEligibility) starts within REMIND_DAYS_BEFORE days and
 * that was made before that window opened - a listing made in the last two weeks before the event gets none, its player
 * has just looked. Each one is marked first (once per listing, whatever the e-mail does), then its e-mail goes async.
 */
#[AsMessageHandler]
readonly final class RemindPartnerListingsHandler
{
    public const int REMIND_DAYS_BEFORE = 14;

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
    AND c.date_from::date > CAST(:marketplace_today AS date)
    AND c.date_from::date <= CAST(:marketplace_today AS date) + {$days}
    AND pl.created_at < c.date_from::date - {$days}
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
