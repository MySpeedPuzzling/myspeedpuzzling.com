<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEligibleForMarketplace;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Results\MarketplaceEventForListing;

/**
 * Which events the marketplace works with ("marketplace events", docs/features/marketplace/11-events.md):
 * in person, publicly visible (the one rule of IsCompetitionPubliclyVisible), dated and not over yet - standalone
 * events and series editions alike. "Today" comes from the clock, an event stays a marketplace event through its
 * last day.
 *
 * A player is going to an event when a competition_participant row connects them to it and is going
 * (CompetitionParticipantGoing - not deleted, not on the waitlist of an event that manages registration).
 *
 * @phpstan-import-type MarketplaceEventDatabaseRow from MarketplaceEvent
 */
readonly final class GetMarketplaceEvents
{
    /**
     * The qualifying rule as a WHERE fragment, so every statement embeds the same rule instead of re-typing it.
     * Requires aliases `c` = competition and `cs` = competition_series LEFT JOINed on `cs.id = c.series_id`,
     * and binds `:marketplace_today` - pass todayParameter() along with the statement's own parameters.
     */
    public const string SQL_QUALIFIES = '(c.is_online = false'
        . ' AND c.date_from IS NOT NULL'
        . ' AND COALESCE(c.date_to, c.date_from)::date >= CAST(:marketplace_today AS date)'
        . ' AND (' . IsCompetitionPubliclyVisible::SQL_CONDITION . '))';

    private const string SELECT_EVENT = <<<SQL
SELECT c.id,
    c.name,
    c.shortcut,
    c.slug,
    c.date_from,
    c.date_to,
    COALESCE(c.location, cs.location) AS location,
    COALESCE(c.location_country_code, cs.location_country_code) AS location_country_code,
    cs.name AS series_name,
    cs.slug AS series_slug
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{marketplace_today: string}
     */
    public function todayParameter(): array
    {
        return ['marketplace_today' => $this->clock->now()->format('Y-m-d')];
    }

    /**
     * "This player is going to that event" as an `EXISTS (…)` fragment. Both arguments are SQL expressions
     * (a column like `c.id` or a placeholder like `:playerId`), never values.
     */
    public static function sqlPlayerGoing(string $competitionIdSql, string $playerIdSql): string
    {
        $going = CompetitionParticipantGoing::sql('going_participant');

        return <<<SQL
EXISTS (
    SELECT 1
    FROM competition_participant going_participant
    WHERE going_participant.competition_id = {$competitionIdSql}
        AND going_participant.player_id = {$playerIdSql}
        AND {$going}
)
SQL;
    }

    public function qualifies(string $competitionId): bool
    {
        if (Uuid::isValid($competitionId) === false) {
            return false;
        }

        $qualifies = self::SQL_QUALIFIES;

        $query = <<<SQL
SELECT 1
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :competitionId
    AND {$qualifies}
LIMIT 1
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                ...$this->todayParameter(),
            ])
            ->fetchOne();

        return $result !== false;
    }

    /**
     * @throws CompetitionNotEligibleForMarketplace
     */
    public function byId(string $competitionId): MarketplaceEvent
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotEligibleForMarketplace();
        }

        $select = self::SELECT_EVENT;
        $qualifies = self::SQL_QUALIFIES;

        $query = <<<SQL
{$select}
WHERE c.id = :competitionId
    AND {$qualifies}
SQL;

        $row = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                ...$this->todayParameter(),
            ])
            ->fetchAssociative();

        if ($row === false) {
            throw new CompetitionNotEligibleForMarketplace();
        }

        /** @var MarketplaceEventDatabaseRow $row */
        return MarketplaceEvent::fromDatabaseRow($row);
    }

    /**
     * Attendance only - whether the event qualifies is a separate question (qualifies()).
     */
    public function isPlayerGoing(string $competitionId, string $playerId): bool
    {
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($playerId) === false) {
            return false;
        }

        $going = self::sqlPlayerGoing(':competitionId', ':playerId');

        $result = $this->database
            ->executeQuery("SELECT 1 WHERE {$going}", [
                'competitionId' => $competitionId,
                'playerId' => $playerId,
            ])
            ->fetchOne();

        return $result !== false;
    }

    /**
     * The marketplace events the player is going to, nearest first.
     *
     * @return list<MarketplaceEvent>
     */
    public function forPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        $select = self::SELECT_EVENT;
        $qualifies = self::SQL_QUALIFIES;
        $going = self::sqlPlayerGoing('c.id', ':playerId');

        $query = <<<SQL
{$select}
WHERE {$qualifies}
    AND {$going}
ORDER BY c.date_from ASC, c.name ASC, c.id ASC
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                ...$this->todayParameter(),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): MarketplaceEvent {
            /** @var MarketplaceEventDatabaseRow $row */
            return MarketplaceEvent::fromDatabaseRow($row);
        }, $rows);
    }

    /**
     * Right after "I'm going" (F1 / F2 in docs/features/marketplace/11-events.md), one statement: does the event
     * qualify, and - asked only for members, `$withListings` - does the player have a published listing.
     * `hasPublishedListings` is false whenever it was not asked.
     *
     * @return array{qualifies: bool, hasPublishedListings: bool}
     */
    public function joinFollowUp(string $competitionId, string $playerId, bool $withListings): array
    {
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($playerId) === false) {
            return ['qualifies' => false, 'hasPublishedListings' => false];
        }

        $qualifies = self::SQL_QUALIFIES;
        $parameters = [
            'competitionId' => $competitionId,
            ...$this->todayParameter(),
        ];
        $listings = 'false';

        if ($withListings) {
            $listings = <<<SQL
EXISTS (
    SELECT 1
    FROM sell_swap_list_item published_listing
    WHERE published_listing.player_id = :playerId
        AND published_listing.published_on_marketplace = true
)
SQL;
            $parameters['playerId'] = $playerId;
        }

        $query = <<<SQL
SELECT
    EXISTS (
        SELECT 1
        FROM competition c
        LEFT JOIN competition_series cs ON cs.id = c.series_id
        WHERE c.id = :competitionId
            AND {$qualifies}
    ) AS qualifies,
    {$listings} AS has_published_listings
SQL;

        /** @var array{qualifies: bool, has_published_listings: bool} $row */
        $row = $this->database
            ->executeQuery($query, $parameters)
            ->fetchAssociative();

        return [
            'qualifies' => (bool) $row['qualifies'],
            'hasPublishedListings' => (bool) $row['qualifies'] && (bool) $row['has_published_listings'],
        ];
    }

    /**
     * The marketplace events the seller goes to (nearest first), each with whether this listing is marked for it and -
     * in a conversation - whether the other player goes too. One statement: the listing form's preselection and the
     * chat's "Bring to event" menu.
     *
     * @return list<MarketplaceEventForListing>
     */
    public function forListingSeller(string $sellerId, string $listItemId, null|string $otherPlayerId = null): array
    {
        if (Uuid::isValid($sellerId) === false || Uuid::isValid($listItemId) === false) {
            return [];
        }

        $select = self::SELECT_EVENT;
        $qualifies = self::SQL_QUALIFIES;
        $going = self::sqlPlayerGoing('c.id', ':sellerId');
        $parameters = [
            'sellerId' => $sellerId,
            'listItemId' => $listItemId,
            ...$this->todayParameter(),
        ];
        $otherGoing = 'false';

        if ($otherPlayerId !== null && Uuid::isValid($otherPlayerId)) {
            $otherGoing = self::sqlPlayerGoing('marketplace_event.id', ':otherPlayerId');
            $parameters['otherPlayerId'] = $otherPlayerId;
        }

        $query = <<<SQL
SELECT marketplace_event.*,
    EXISTS (
        SELECT 1
        FROM sell_swap_list_item_event bringing_event
        WHERE bringing_event.competition_id = marketplace_event.id
            AND bringing_event.sell_swap_list_item_id = :listItemId
    ) AS bringing,
    {$otherGoing} AS other_player_going
FROM (
    {$select}
    WHERE {$qualifies}
        AND {$going}
) marketplace_event
ORDER BY marketplace_event.date_from ASC, marketplace_event.name ASC, marketplace_event.id ASC
SQL;

        $rows = $this->database
            ->executeQuery($query, $parameters)
            ->fetchAllAssociative();

        return array_map(static function (array $row): MarketplaceEventForListing {
            /** @var array{bringing: bool, other_player_going: bool} $flags */
            $flags = ['bringing' => $row['bringing'], 'other_player_going' => $row['other_player_going']];
            unset($row['bringing'], $row['other_player_going']);

            /** @var MarketplaceEventDatabaseRow $row */
            return new MarketplaceEventForListing(
                event: MarketplaceEvent::fromDatabaseRow($row),
                bringing: (bool) $flags['bringing'],
                otherPlayerGoing: (bool) $flags['other_player_going'],
            );
        }, $rows);
    }
}
