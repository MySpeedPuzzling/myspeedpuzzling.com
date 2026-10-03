<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Results\MarketplaceEvent;
use SpeedPuzzling\Web\Results\MarketplaceListingItem;
use SpeedPuzzling\Web\Results\MarketplaceListingsCount;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;

/**
 * The marketplace's listings. Marketplace at events (docs/features/marketplace/11-events.md):
 *
 * - `$event` (a marketplace event's id) narrows the list to the published listings of the sellers going to it,
 *   the ones they are bringing first (`bringing`), whatever the sort - the shipping filters do not apply then, it is
 *   a hand-over in person. The statement checks the event qualifies itself (an event that does not lists nothing),
 *   turning an invalid value into "no event" is the caller's job (MarketplaceListing resolves it before asking).
 * - Every row of a page carries its labels, computed in the same statement for the page's rows only: `bringingTo`
 *   for everyone, `sellerGoesTo` for the `$viewerId` - see MarketplaceListingItem.
 *
 * @phpstan-import-type MarketplaceEventDatabaseRow from MarketplaceEvent
 *
 * @phpstan-type ListingDatabaseRow array{
 *     item_id: string,
 *     puzzle_id: string,
 *     puzzle_name: string,
 *     puzzle_alternative_name: string|null,
 *     pieces_count: int,
 *     puzzle_image: string|null,
 *     puzzle_image_ratio: string|null,
 *     manufacturer_name: string|null,
 *     listing_type: string,
 *     price: string|null,
 *     condition: string,
 *     comment: string|null,
 *     reserved: bool,
 *     added_at: string,
 *     seller_id: string,
 *     seller_name: string|null,
 *     seller_code: string|null,
 *     seller_avatar: string|null,
 *     seller_country: string|null,
 *     sell_swap_list_settings: string|null,
 *     seller_rating_count: int|string,
 *     seller_average_rating: string|null,
 *     reserved_for_player_id: string|null,
 *     reserved_for_player_name: string|null,
 *     bringing?: bool,
 * }
 */
readonly final class GetMarketplaceListings
{
    private const string COLUMNS = '
    ssli.id AS item_id,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.alternative_name AS puzzle_alternative_name,
    p.pieces_count,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS puzzle_image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS puzzle_image_ratio,
    m.name AS manufacturer_name,
    ssli.listing_type,
    ssli.price,
    ssli.condition,
    ssli.comment,
    ssli.reserved,
    ssli.added_at,
    pl.id AS seller_id,
    pl.name AS seller_name,
    pl.code AS seller_code,
    pl.avatar AS seller_avatar,
    pl.country AS seller_country,
    pl.sell_swap_list_settings,
    pl.rating_count AS seller_rating_count,
    pl.average_rating AS seller_average_rating,
    ssli.reserved_for_player_id,
    COALESCE(rp.name, CHR(35) || UPPER(rp.code)) AS reserved_for_player_name';

    private const string FROM = '
FROM sell_swap_list_item ssli
JOIN puzzle p ON ssli.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
JOIN player pl ON ssli.player_id = pl.id';

    /** The event label columns (aliases c = competition, cs = competition_series) - MarketplaceEvent::fromDatabaseRow() */
    private const string EVENT_COLUMNS = 'c.id,
        c.name,
        c.shortcut,
        c.slug,
        c.date_from,
        c.date_to,
        COALESCE(c.location, cs.location) AS location,
        COALESCE(c.location_country_code, cs.location_country_code) AS location_country_code,
        cs.name AS series_name,
        cs.slug AS series_slug';

    private const array EVENT_COLUMN_NAMES = [
        'id', 'name', 'shortcut', 'slug', 'date_from', 'date_to',
        'location', 'location_country_code', 'series_name', 'series_slug',
    ];

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private GetMarketplaceEvents $getMarketplaceEvents,
    ) {
    }

    /**
     * @param list<int> $difficultyTiers DifficultyTier values and/or PuzzleSearchCriteria::UNRATED_DIFFICULTY (members;
     *                                   the caller gates it)
     * @param null|string $event a marketplace event's competition id, see the class description
     * @param bool $onlyBringing with an event: only the listings marked for it
     * @param null|string $viewerId the signed-in player, for the "Seller goes to …" label
     *
     * @return array<MarketplaceListingItem>
     */
    public function search(
        null|string $searchTerm = null,
        null|string $manufacturerId = null,
        null|int $piecesMin = null,
        null|int $piecesMax = null,
        null|ListingType $listingType = null,
        null|float $priceMin = null,
        null|float $priceMax = null,
        null|PuzzleCondition $condition = null,
        null|string $shipsToCountry = null,
        null|string $sellerCountry = null,
        null|string $sellerId = null,
        null|string $puzzleId = null,
        string $sort = 'newest',
        int $limit = 24,
        int $offset = 0,
        array $difficultyTiers = [],
        null|string $event = null,
        bool $onlyBringing = false,
        null|string $viewerId = null,
    ): array {
        $hasSearch = $searchTerm !== null && $searchTerm !== '';
        $event = self::validUuid($event);
        $viewerId = self::validUuid($viewerId);

        [$joins, $conditions, $params, $types] = $this->filters(
            searchTerm: $searchTerm,
            manufacturerId: $manufacturerId,
            piecesMin: $piecesMin,
            piecesMax: $piecesMax,
            listingType: $listingType,
            priceMin: $priceMin,
            priceMax: $priceMax,
            condition: $condition,
            shipsToCountry: $shipsToCountry,
            sellerCountry: $sellerCountry,
            sellerId: $sellerId,
            puzzleId: $puzzleId,
            difficultyTiers: $difficultyTiers,
            event: $event,
        );

        $bringing = $event !== null ? self::sqlBringing() : 'false';

        if ($event !== null && $onlyBringing) {
            $conditions .= "\n    AND {$bringing}";
        }

        $matchScore = '';

        if ($hasSearch && $sort === 'relevance') {
            $eanSearch = trim((string) $searchTerm, '0');
            $matchScore = ',
    CASE
        WHEN p.alternative_name ILIKE :searchQuery
          OR p.name ILIKE :searchQuery
          OR p.identification_number = :searchQuery
          OR p.ean = :eanSearchQuery THEN 7
        WHEN immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchQuery)
          OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchQuery) THEN 6
        WHEN p.identification_number ILIKE :searchEndLikeQuery
          OR p.identification_number ILIKE :searchStartLikeQuery
          OR p.ean ILIKE :eanSearchEndLikeQuery
          OR p.ean ILIKE :eanSearchStartLikeQuery THEN 5
        WHEN p.alternative_name ILIKE :searchEndLikeQuery
          OR p.alternative_name ILIKE :searchStartLikeQuery
          OR p.name ILIKE :searchEndLikeQuery
          OR p.name ILIKE :searchStartLikeQuery THEN 4
        WHEN immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchEndLikeQuery)
          OR immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchStartLikeQuery)
          OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchEndLikeQuery)
          OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchStartLikeQuery) THEN 3
        WHEN p.identification_number ILIKE :searchFullLikeQuery
          OR p.ean ILIKE :eanSearchFullLikeQuery THEN 2
        WHEN p.alternative_name ILIKE :searchFullLikeQuery
          OR p.name ILIKE :searchFullLikeQuery THEN 1
        ELSE 0
    END AS match_score';
            $params['searchQuery'] = $searchTerm;
            $params['searchStartLikeQuery'] = '%' . $searchTerm;
            $params['searchEndLikeQuery'] = $searchTerm . '%';
            $params['eanSearchQuery'] = $eanSearch;
            $params['eanSearchStartLikeQuery'] = '%' . $eanSearch;
            $params['eanSearchEndLikeQuery'] = $eanSearch . '%';
        }

        $orderKeys = self::orderKeys($sort, $hasSearch, $event !== null);
        $columns = self::COLUMNS;
        $from = self::FROM;
        $innerOrder = implode(', ', $orderKeys);
        $outerOrder = implode(', ', array_map(static fn (string $key): string => 'page.' . $key, $orderKeys));

        // The page first (filters, order, limit), then its rows' labels - never computed for rows off the page
        $page = <<<SQL
SELECT {$columns},
    {$bringing} AS bringing{$matchScore}
{$from}
LEFT JOIN player rp ON ssli.reserved_for_player_id = rp.id{$joins}
WHERE ssli.published_on_marketplace = true{$conditions}
ORDER BY {$innerOrder}
LIMIT :limit OFFSET :offset
SQL;

        [$with, $labelColumns, $labelJoins, $labelParams] = $event === null
            ? $this->labels($viewerId)
            : ['', '', '', []];

        $query = <<<SQL
{$with}SELECT page.*{$labelColumns}
FROM (
{$page}
) page{$labelJoins}
ORDER BY {$outerOrder}
SQL;

        $params = [
            ...$params,
            ...$labelParams,
            'limit' => $limit,
            'offset' => $offset,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];

        $rows = $this->database
            ->executeQuery($query, $params, $types)
            ->fetchAllAssociative();

        return array_map(self::hydrate(...), $rows);
    }

    public function byItemId(string $itemId): MarketplaceListingItem
    {
        $notHidden = $this->hiddenPlayers->sqlExclude('pl.id');
        $columns = self::COLUMNS;
        $from = self::FROM;
        [$with, $labelColumns, $labelJoins, $labelParams] = $this->labels(null);

        $query = <<<SQL
{$with}SELECT page.*{$labelColumns}
FROM (
SELECT {$columns}
{$from}
LEFT JOIN player rp ON ssli.reserved_for_player_id = rp.id
WHERE ssli.id = :itemId
    {$notHidden}
) page{$labelJoins}
SQL;

        $row = $this->database
            ->executeQuery($query, [
                'itemId' => $itemId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                ...$labelParams,
            ])
            ->fetchAssociative();

        if ($row === false) {
            throw new SellSwapListItemNotFound();
        }

        return self::hydrate($row);
    }

    /**
     * @param list<int> $difficultyTiers as in search()
     */
    public function count(
        null|string $searchTerm = null,
        null|string $manufacturerId = null,
        null|int $piecesMin = null,
        null|int $piecesMax = null,
        null|ListingType $listingType = null,
        null|float $priceMin = null,
        null|float $priceMax = null,
        null|PuzzleCondition $condition = null,
        null|string $shipsToCountry = null,
        null|string $sellerCountry = null,
        null|string $sellerId = null,
        null|string $puzzleId = null,
        array $difficultyTiers = [],
        null|string $event = null,
        bool $onlyBringing = false,
    ): int {
        return $this->countParts(
            searchTerm: $searchTerm,
            manufacturerId: $manufacturerId,
            piecesMin: $piecesMin,
            piecesMax: $piecesMax,
            listingType: $listingType,
            priceMin: $priceMin,
            priceMax: $priceMax,
            condition: $condition,
            shipsToCountry: $shipsToCountry,
            sellerCountry: $sellerCountry,
            sellerId: $sellerId,
            puzzleId: $puzzleId,
            difficultyTiers: $difficultyTiers,
            event: $event,
            onlyBringing: $onlyBringing,
        )->total;
    }

    /**
     * The count, and under the event filter both parts of the list - one statement.
     *
     * @param list<int> $difficultyTiers as in search()
     */
    public function countParts(
        null|string $searchTerm = null,
        null|string $manufacturerId = null,
        null|int $piecesMin = null,
        null|int $piecesMax = null,
        null|ListingType $listingType = null,
        null|float $priceMin = null,
        null|float $priceMax = null,
        null|PuzzleCondition $condition = null,
        null|string $shipsToCountry = null,
        null|string $sellerCountry = null,
        null|string $sellerId = null,
        null|string $puzzleId = null,
        array $difficultyTiers = [],
        null|string $event = null,
        bool $onlyBringing = false,
    ): MarketplaceListingsCount {
        $event = self::validUuid($event);

        [$joins, $conditions, $params, $types] = $this->filters(
            searchTerm: $searchTerm,
            manufacturerId: $manufacturerId,
            piecesMin: $piecesMin,
            piecesMax: $piecesMax,
            listingType: $listingType,
            priceMin: $priceMin,
            priceMax: $priceMax,
            condition: $condition,
            shipsToCountry: $shipsToCountry,
            sellerCountry: $sellerCountry,
            sellerId: $sellerId,
            puzzleId: $puzzleId,
            difficultyTiers: $difficultyTiers,
            event: $event,
        );

        // Both parts are counted even when only the bringing ones are listed - the header shows them
        $bringingCount = $event !== null ? 'COUNT(*) FILTER (WHERE ' . self::sqlBringing() . ')' : '0';
        $from = self::FROM;

        $query = <<<SQL
SELECT COUNT(*) AS total,
    {$bringingCount} AS bringing
{$from}{$joins}
WHERE ssli.published_on_marketplace = true{$conditions}
SQL;

        /** @var array{total: int|string, bringing: int|string} $row */
        $row = $this->database
            ->executeQuery($query, $params, $types)
            ->fetchAssociative();

        $all = (int) $row['total'];
        $bringing = (int) $row['bringing'];

        if ($event === null) {
            return new MarketplaceListingsCount(total: $all);
        }

        return new MarketplaceListingsCount(
            total: $onlyBringing ? $bringing : $all,
            bringing: $bringing,
            toAsk: $all - $bringing,
        );
    }

    /**
     * @return array<array{manufacturer_id: string, manufacturer_name: string, listing_count: int}>
     */
    public function getManufacturersWithActiveListings(): array
    {
        $query = <<<SQL
SELECT
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    COUNT(*) AS listing_count
FROM sell_swap_list_item ssli
JOIN puzzle p ON ssli.puzzle_id = p.id
JOIN manufacturer m ON p.manufacturer_id = m.id
WHERE ssli.published_on_marketplace = true
GROUP BY m.id, m.name
HAVING COUNT(*) > 0
ORDER BY listing_count DESC, m.name ASC
SQL;

        /** @var array<array{manufacturer_id: string, manufacturer_name: string, listing_count: int}> $rows */
        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * The WHERE conditions (after "published") shared by search() and the count, so the two never disagree.
     *
     * @param list<int> $difficultyTiers
     * @return array{string, string, array<string, mixed>, array<string, ArrayParameterType>} joins, conditions, params, types
     */
    private function filters(
        null|string $searchTerm,
        null|string $manufacturerId,
        null|int $piecesMin,
        null|int $piecesMax,
        null|ListingType $listingType,
        null|float $priceMin,
        null|float $priceMax,
        null|PuzzleCondition $condition,
        null|string $shipsToCountry,
        null|string $sellerCountry,
        null|string $sellerId,
        null|string $puzzleId,
        array $difficultyTiers,
        null|string $event,
    ): array {
        [$difficultyJoin, $difficultyCondition, $ratedTiers] = self::difficultyFilter($difficultyTiers);

        $conditions = $this->hiddenPlayers->sqlExclude('pl.id') . $difficultyCondition;
        $params = [];
        $types = [];

        if ($searchTerm !== null && $searchTerm !== '') {
            $conditions .= '
    AND (
        p.alternative_name ILIKE :searchFullLikeQuery
        OR p.name ILIKE :searchFullLikeQuery
        OR immutable_unaccent(p.alternative_name) ILIKE immutable_unaccent(:searchFullLikeQuery)
        OR immutable_unaccent(p.name) ILIKE immutable_unaccent(:searchFullLikeQuery)
        OR p.identification_number ILIKE :searchFullLikeQuery
        OR p.ean ILIKE :eanSearchFullLikeQuery
    )';
            $params['searchFullLikeQuery'] = '%' . $searchTerm . '%';
            $params['eanSearchFullLikeQuery'] = '%' . trim($searchTerm, '0') . '%';
        }

        if ($manufacturerId !== null && $manufacturerId !== '') {
            $conditions .= '
    AND p.manufacturer_id = :manufacturerId';
            $params['manufacturerId'] = $manufacturerId;
        }

        if ($piecesMin !== null) {
            $conditions .= '
    AND p.pieces_count >= :piecesMin';
            $params['piecesMin'] = $piecesMin;
        }

        if ($piecesMax !== null) {
            $conditions .= '
    AND p.pieces_count <= :piecesMax';
            $params['piecesMax'] = $piecesMax;
        }

        if ($listingType !== null) {
            $conditions .= '
    AND ssli.listing_type = :listingType';
            $params['listingType'] = $listingType->value;
        }

        if ($priceMin !== null) {
            $conditions .= '
    AND ssli.price >= :priceMin';
            $params['priceMin'] = $priceMin;
        }

        if ($priceMax !== null) {
            $conditions .= '
    AND ssli.price <= :priceMax';
            $params['priceMax'] = $priceMax;
        }

        if ($condition !== null) {
            $conditions .= '
    AND ssli.condition = :condition';
            $params['condition'] = $condition->value;
        }

        if ($event !== null) {
            // A hand-over in person: whoever is going, wherever they ship to and live
            $qualifies = GetMarketplaceEvents::SQL_QUALIFIES;
            $going = GetMarketplaceEvents::sqlPlayerGoing(':event', 'ssli.player_id');

            $conditions .= "
    AND EXISTS (
        SELECT 1
        FROM competition c
        LEFT JOIN competition_series cs ON cs.id = c.series_id
        WHERE c.id = :event
            AND {$qualifies}
    )
    AND {$going}";
            $params['event'] = $event;
            $params = [...$params, ...$this->getMarketplaceEvents->todayParameter()];
        } else {
            if ($shipsToCountry !== null && $shipsToCountry !== '') {
                $conditions .= "
    AND (pl.sell_swap_list_settings->'shipping_countries')::jsonb @> :countryJson::jsonb";
                $params['countryJson'] = json_encode($shipsToCountry, JSON_THROW_ON_ERROR);
            }

            if ($sellerCountry !== null && $sellerCountry !== '') {
                $conditions .= '
    AND pl.country = :sellerCountry';
                $params['sellerCountry'] = $sellerCountry;
            }
        }

        if ($sellerId !== null && $sellerId !== '') {
            $conditions .= '
    AND ssli.player_id = :sellerId';
            $params['sellerId'] = $sellerId;
        }

        if ($puzzleId !== null && $puzzleId !== '') {
            $conditions .= '
    AND p.id = :puzzleId';
            $params['puzzleId'] = $puzzleId;
        }

        if ($ratedTiers !== []) {
            $params['difficultyTiers'] = $ratedTiers;
            $types['difficultyTiers'] = ArrayParameterType::INTEGER;
        }

        return [$difficultyJoin, $conditions, $params, $types];
    }

    /**
     * The labels of a page's rows (alias `page`): the nearest marketplace event the listing is marked for while
     * the seller still goes, and - for a viewer - the nearest one both the seller and the viewer go to that the
     * listing is not marked for. A past event's or a left event's mark labels nothing.
     *
     * @return array{string, string, string, array<string, string>} WITH clause, columns, joins, params
     */
    private function labels(null|string $viewerId): array
    {
        $eventColumns = self::EVENT_COLUMNS;
        $qualifies = GetMarketplaceEvents::SQL_QUALIFIES;
        $sellerGoing = GetMarketplaceEvents::sqlPlayerGoing('c.id', 'page.seller_id');

        $columns = self::prefixedEventColumns('bringing_to');
        $joins = <<<SQL

LEFT JOIN LATERAL (
    SELECT {$eventColumns}
    FROM sell_swap_list_item_event marked
    JOIN competition c ON c.id = marked.competition_id
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    WHERE marked.sell_swap_list_item_id = page.item_id
        AND {$qualifies}
        AND {$sellerGoing}
    ORDER BY c.date_from ASC, c.name ASC, c.id ASC
    LIMIT 1
) bringing_to ON true
SQL;
        $params = $this->getMarketplaceEvents->todayParameter();

        if ($viewerId === null) {
            return ['', $columns, $joins, $params];
        }

        // The viewer's marketplace events, once per statement (a handful at most), then per row of the page
        $viewerGoing = GetMarketplaceEvents::sqlPlayerGoing('c.id', ':viewerId');
        $sellerGoingThere = GetMarketplaceEvents::sqlPlayerGoing('viewer_events.id', 'page.seller_id');

        $with = <<<SQL
WITH viewer_events AS MATERIALIZED (
    SELECT {$eventColumns}
    FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    WHERE {$qualifies}
        AND {$viewerGoing}
)

SQL;
        $columns .= self::prefixedEventColumns('seller_goes_to');
        $joins .= <<<SQL

LEFT JOIN LATERAL (
    SELECT viewer_events.*
    FROM viewer_events
    WHERE page.seller_id <> :viewerId
        AND {$sellerGoingThere}
        AND NOT EXISTS (
            SELECT 1
            FROM sell_swap_list_item_event marked
            WHERE marked.sell_swap_list_item_id = page.item_id
                AND marked.competition_id = viewer_events.id
        )
    ORDER BY viewer_events.date_from ASC, viewer_events.name ASC, viewer_events.id ASC
    LIMIT 1
) seller_goes_to ON true
SQL;
        $params['viewerId'] = $viewerId;

        return [$with, $columns, $joins, $params];
    }

    private static function prefixedEventColumns(string $alias): string
    {
        return implode('', array_map(
            static fn (string $column): string => ",\n    {$alias}.{$column} AS {$alias}_{$column}",
            self::EVENT_COLUMN_NAMES,
        ));
    }

    /**
     * Requires the `:event` parameter (search() and the count bind it with the event filter)
     */
    private static function sqlBringing(): string
    {
        return 'EXISTS (
        SELECT 1
        FROM sell_swap_list_item_event bringing_event
        WHERE bringing_event.sell_swap_list_item_id = ssli.id
            AND bringing_event.competition_id = :event
    )';
    }

    /**
     * Output column names of the page statement, so the page and the labelled rows around it sort the same way.
     * The listings they are bringing come first under the event filter, the item id breaks ties.
     *
     * @return non-empty-list<string>
     */
    private static function orderKeys(string $sort, bool $hasSearch, bool $byEvent): array
    {
        $keys = match (true) {
            $hasSearch && $sort === 'relevance' => ['match_score DESC', 'added_at DESC'],
            $sort === 'price_asc' => ['price ASC NULLS LAST', 'added_at DESC'],
            $sort === 'price_desc' => ['price DESC NULLS LAST', 'added_at DESC'],
            $sort === 'name_asc' => ['puzzle_name ASC', 'added_at DESC'],
            $sort === 'name_desc' => ['puzzle_name DESC', 'added_at DESC'],
            default => ['added_at DESC'],
        };

        if ($byEvent) {
            array_unshift($keys, 'bringing DESC');
        }

        $keys[] = 'item_id DESC';

        return $keys;
    }

    private static function validUuid(null|string $id): null|string
    {
        if ($id === null || Uuid::isValid($id) === false) {
            return null;
        }

        return strtolower($id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): MarketplaceListingItem
    {
        /** @var ListingDatabaseRow $row */
        $currency = null;
        $customCurrency = null;
        $shippingCost = null;

        if ($row['sell_swap_list_settings'] !== null) {
            /** @var array{currency?: string|null, custom_currency?: string|null, shipping_cost?: string|null} $settings */
            $settings = json_decode($row['sell_swap_list_settings'], true);
            $currency = $settings['currency'] ?? null;
            $customCurrency = $settings['custom_currency'] ?? null;
            $shippingCost = $settings['shipping_cost'] ?? null;

            if ($currency === 'custom') {
                $currency = null;
            }
        }

        return new MarketplaceListingItem(
            itemId: $row['item_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            piecesCount: (int) $row['pieces_count'],
            puzzleImage: $row['puzzle_image'],
            puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
            manufacturerName: $row['manufacturer_name'],
            listingType: $row['listing_type'],
            price: $row['price'] !== null ? (float) $row['price'] : null,
            condition: $row['condition'],
            comment: $row['comment'],
            reserved: (bool) $row['reserved'],
            reservedForPlayerId: $row['reserved_for_player_id'],
            reservedForPlayerName: $row['reserved_for_player_name'],
            addedAt: $row['added_at'],
            sellerId: $row['seller_id'],
            sellerName: $row['seller_name'],
            sellerCode: $row['seller_code'],
            sellerAvatar: $row['seller_avatar'],
            sellerCountry: $row['seller_country'],
            sellerCurrency: $currency,
            sellerCustomCurrency: $customCurrency,
            sellerShippingCost: $shippingCost,
            sellerRatingCount: (int) $row['seller_rating_count'],
            sellerAverageRating: $row['seller_average_rating'] !== null ? (float) $row['seller_average_rating'] : null,
            bringing: (bool) ($row['bringing'] ?? false),
            bringingTo: self::eventLabel($row, 'bringing_to'),
            sellerGoesTo: self::eventLabel($row, 'seller_goes_to'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function eventLabel(array $row, string $alias): null|MarketplaceEvent
    {
        if (($row[$alias . '_id'] ?? null) === null) {
            return null;
        }

        $eventRow = [];

        foreach (self::EVENT_COLUMN_NAMES as $column) {
            $eventRow[$column] = $row[$alias . '_' . $column];
        }

        /** @var MarketplaceEventDatabaseRow $eventRow */
        return MarketplaceEvent::fromDatabaseRow($eventRow);
    }

    /**
     * Same meaning as the puzzle search (SearchPuzzle::difficultyFilter()): the tiers, "not rated yet" = no tier.
     * puzzle_difficulty is keyed by the puzzle, so the join never adds rows. Measured on the dev copy (1,482 listings,
     * 2026-10-03): a wide choice +2-4 ms on the page query, a narrow one makes it faster (starts from the tier index).
     *
     * @param list<int> $difficultyTiers
     * @return array{string, string, list<int>} join, condition, rated tiers to bind
     */
    private static function difficultyFilter(array $difficultyTiers): array
    {
        if ($difficultyTiers === []) {
            return ['', '', []];
        }

        $ratedTiers = array_values(array_filter(
            $difficultyTiers,
            static fn (int $tier): bool => $tier !== PuzzleSearchCriteria::UNRATED_DIFFICULTY,
        ));

        $conditions = [];

        if ($ratedTiers !== []) {
            $conditions[] = 'pd.difficulty_tier IN (:difficultyTiers)';
        }

        if (count($ratedTiers) !== count($difficultyTiers)) {
            $conditions[] = 'pd.difficulty_tier IS NULL';
        }

        return [
            "\nLEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id",
            "\n    AND (" . implode(' OR ', $conditions) . ')',
            $ratedTiers,
        ];
    }
}
