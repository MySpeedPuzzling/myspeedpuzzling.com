<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\EventOfferChoice;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * The "What will you bring?" picker (docs/features/marketplace/11-events.md): the seller's published listings with
 * their links in one statement - marked for this event or not, and the other marketplace events (qualifying, the
 * seller still going) each is marked for. History rows of past events never show up.
 */
readonly final class GetEventOfferChoices
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private GetMarketplaceEvents $getMarketplaceEvents,
    ) {
    }

    /**
     * @return list<EventOfferChoice>
     */
    public function forPicker(string $playerId, string $competitionId): array
    {
        if (Uuid::isValid($playerId) === false || Uuid::isValid($competitionId) === false) {
            return [];
        }

        $qualifies = GetMarketplaceEvents::SQL_QUALIFIES;
        $going = GetMarketplaceEvents::sqlPlayerGoing('c.id', 'ssli.player_id');

        $query = <<<SQL
SELECT
    ssli.id AS item_id,
    ssli.listing_type,
    ssli.price,
    ssli.condition,
    ssli.reserved,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS image_ratio,
    m.name AS manufacturer_name,
    EXISTS (
        SELECT 1
        FROM sell_swap_list_item_event this_event
        WHERE this_event.sell_swap_list_item_id = ssli.id
            AND this_event.competition_id = :competitionId
    ) AS bringing,
    (
        SELECT json_agg(COALESCE(NULLIF(c.shortcut, ''), c.name) ORDER BY c.date_from, c.name)
        FROM sell_swap_list_item_event other_event
        JOIN competition c ON c.id = other_event.competition_id
        LEFT JOIN competition_series cs ON cs.id = c.series_id
        WHERE other_event.sell_swap_list_item_id = ssli.id
            AND other_event.competition_id <> :competitionId
            AND {$qualifies}
            AND {$going}
    ) AS also_at
FROM sell_swap_list_item ssli
JOIN puzzle p ON p.id = ssli.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE ssli.player_id = :playerId
    AND ssli.published_on_marketplace = true
ORDER BY ssli.added_at DESC, ssli.id DESC
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'competitionId' => $competitionId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                ...$this->getMarketplaceEvents->todayParameter(),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): EventOfferChoice {
            /**
             * @var array{
             *     item_id: string,
             *     listing_type: string,
             *     price: null|float|string,
             *     condition: string,
             *     reserved: bool,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     pieces_count: int,
             *     image: null|string,
             *     image_ratio: null|float|string,
             *     manufacturer_name: null|string,
             *     bringing: bool,
             *     also_at: null|string,
             * } $row
             */

            /** @var list<string> $alsoAt */
            $alsoAt = $row['also_at'] !== null ? json_decode($row['also_at'], true, flags: JSON_THROW_ON_ERROR) : [];

            return new EventOfferChoice(
                itemId: $row['item_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                imageRatio: $row['image_ratio'] !== null ? (float) $row['image_ratio'] : null,
                listingType: ListingType::from($row['listing_type']),
                price: $row['price'] !== null ? (float) $row['price'] : null,
                condition: PuzzleCondition::from($row['condition']),
                reserved: (bool) $row['reserved'],
                bringing: (bool) $row['bringing'],
                alsoAt: $alsoAt,
            );
        }, $rows);
    }
}
