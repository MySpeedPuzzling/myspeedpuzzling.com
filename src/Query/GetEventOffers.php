<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\EventOffersSeller;
use SpeedPuzzling\Web\Results\EventOffersSummary;
use SpeedPuzzling\Web\Services\HiddenPlayers;

/**
 * The marketplace card of an event page (docs/features/marketplace/11-events.md, "E2"): what the sellers going to the
 * event are bringing, what buyers can ask them to bring, a few of their faces and the viewer's own line - ONE
 * statement, and only for a marketplace event: forEventPage() decides that from the event row the page already has,
 * so any other event page runs no query for the card at all.
 *
 * Privacy: the faces leave out the players the viewer has hidden (HiddenPlayers); the counts are aggregates and stay
 * unfiltered. A private profile does not hide a seller here, exactly as on the marketplace the card leads to (a seller
 * offers in public - docs/features/private-profile-allow-list.md, "left as is, deliberately"), and the participant
 * list right below the card names everybody going anyway.
 *
 * @phpstan-import-type EventOffersSellerJsonRow from EventOffersSeller
 */
readonly final class GetEventOffers
{
    public const int SELLER_FACES = 4;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * GetMarketplaceEvents::SQL_QUALIFIES in PHP, from what an event page has already loaded: in person, publicly
     * visible (IsCompetitionPubliclyVisible), dated and not over - an event stays a marketplace event through its last
     * day. Compared by calendar day, like the SQL rule (CompetitionEvent pins dateFrom to 09:00).
     */
    public function isMarketplaceEvent(CompetitionEvent $event, bool $isPubliclyVisible): bool
    {
        if ($event->isOnline || $isPubliclyVisible === false || $event->dateFrom === null) {
            return false;
        }

        $lastDay = $event->dateTo ?? $event->dateFrom;

        return $lastDay->format('Y-m-d') >= $this->clock->now()->format('Y-m-d');
    }

    /**
     * The card's data, or null without a query when the event is no marketplace event.
     */
    public function forEventPage(CompetitionEvent $event, bool $isPubliclyVisible, null|string $viewerPlayerId): null|EventOffersSummary
    {
        if ($this->isMarketplaceEvent($event, $isPubliclyVisible) === false) {
            return null;
        }

        return $this->summary($event->id, $viewerPlayerId);
    }

    /**
     * Does not check whether the event qualifies - the caller does (forEventPage()).
     */
    public function summary(string $competitionId, null|string $viewerPlayerId): EventOffersSummary
    {
        if (Uuid::isValid($competitionId) === false) {
            return new EventOffersSummary(0, 0, 0, [], 0, 0);
        }

        if ($viewerPlayerId !== null && Uuid::isValid($viewerPlayerId) === false) {
            $viewerPlayerId = null;
        }

        $going = GetMarketplaceEvents::sqlPlayerGoing(':competitionId', 'offer_item.player_id');
        // Faces: ranked and cut to SELLER_FACES on event_seller alone (blocks applied before the LIMIT), and only then
        // joined to player - a primary-key lookup for at most four rows instead of a scan of the whole player table
        // feeding a hash join (measured on a production clone: ~8 of 10.9 ms on a WJPC-sized event)
        $hidden = $this->hiddenPlayers->sqlExclude('event_seller.player_id');
        $faces = self::SELLER_FACES;

        // The viewer's line only for a signed-in viewer - a guest's statement does not even mention it
        $viewerColumns = $viewerPlayerId === null
            ? '0 AS viewer_bringing_count, 0 AS viewer_published_count'
            : <<<SQL
(
        SELECT COUNT(*)
        FROM sell_swap_list_item own_item
        JOIN sell_swap_list_item_event own_event ON own_event.sell_swap_list_item_id = own_item.id
            AND own_event.competition_id = :competitionId
        WHERE own_item.player_id = :viewerId
            AND own_item.published_on_marketplace = true
    ) AS viewer_bringing_count,
    (
        SELECT COUNT(*)
        FROM sell_swap_list_item own_item
        WHERE own_item.player_id = :viewerId
            AND own_item.published_on_marketplace = true
    ) AS viewer_published_count
SQL;

        $query = <<<SQL
WITH event_offer AS (
    SELECT offer_item.player_id,
        EXISTS (
            SELECT 1
            FROM sell_swap_list_item_event offer_event
            WHERE offer_event.sell_swap_list_item_id = offer_item.id
                AND offer_event.competition_id = :competitionId
        ) AS bringing
    FROM sell_swap_list_item offer_item
    WHERE offer_item.published_on_marketplace = true
        AND {$going}
),
event_seller AS (
    SELECT event_offer.player_id,
        COUNT(*) FILTER (WHERE event_offer.bringing) AS bringing_count,
        COUNT(*) AS offers_count
    FROM event_offer
    GROUP BY event_offer.player_id
)
SELECT
    (SELECT COUNT(*) FILTER (WHERE event_offer.bringing) FROM event_offer) AS bringing_count,
    (SELECT COUNT(*) FILTER (WHERE NOT event_offer.bringing) FROM event_offer) AS ask_count,
    (SELECT COUNT(*) FROM event_seller) AS sellers_count,
    (
        SELECT json_agg(json_build_object(
            'id', pl.id,
            'name', pl.name,
            'code', pl.code,
            'avatar', pl.avatar,
            'country', pl.country
        ) ORDER BY face.position)
        FROM (
            SELECT event_seller.player_id,
                ROW_NUMBER() OVER (ORDER BY event_seller.bringing_count > 0 DESC, event_seller.offers_count DESC, event_seller.player_id) AS position
            FROM event_seller
            WHERE TRUE{$hidden}
            ORDER BY position
            LIMIT {$faces}
        ) face
        JOIN player pl ON pl.id = face.player_id
    ) AS sellers,
    {$viewerColumns}
SQL;

        $parameters = ['competitionId' => $competitionId];

        if ($viewerPlayerId !== null) {
            $parameters['viewerId'] = $viewerPlayerId;
        }

        /**
         * @var array{
         *     bringing_count: int|string,
         *     ask_count: int|string,
         *     sellers_count: int|string,
         *     sellers: null|string,
         *     viewer_bringing_count: int|string,
         *     viewer_published_count: int|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, $parameters)
            ->fetchAssociative();

        $sellers = [];

        if ($row['sellers'] !== null) {
            /** @var list<EventOffersSellerJsonRow> $sellerRows */
            $sellerRows = json_decode($row['sellers'], true, flags: JSON_THROW_ON_ERROR);

            $sellers = array_map(
                static fn (array $sellerRow): EventOffersSeller => EventOffersSeller::fromJsonRow($sellerRow),
                $sellerRows,
            );
        }

        return new EventOffersSummary(
            bringingCount: (int) $row['bringing_count'],
            askCount: (int) $row['ask_count'],
            sellersCount: (int) $row['sellers_count'],
            sellers: $sellers,
            viewerBringingCount: (int) $row['viewer_bringing_count'],
            viewerPublishedCount: (int) $row['viewer_published_count'],
        );
    }
}
