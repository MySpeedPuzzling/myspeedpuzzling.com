<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\WishlistPuzzleOffers;
use SpeedPuzzling\Web\Services\HiddenPlayers;

/**
 * The marketplace offers of the puzzles on a wishlist page, one statement for the page
 * (docs/features/wishlist-availability.md). An offer counts like a row of the marketplace list: published on the
 * marketplace, not from a seller the viewer blocks (the list's own rule), never a secret puzzle - and also not reserved
 * and not the viewer's own listing, so the chip says what is there for them to have. "Ships to the viewer" is the
 * marketplace's "Ships to my country" rule (the seller's shipping countries).
 */
readonly final class GetWishlistMarketplaceAvailability
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $puzzleIds
     *
     * @return array<string, WishlistPuzzleOffers> by puzzle id - puzzles without an offer are left out
     */
    public function forPuzzles(array $puzzleIds, null|string $viewerId, null|string $viewerCountry): array
    {
        $puzzleIds = array_values(array_unique(array_filter(array_map(strtolower(...), $puzzleIds), static fn (string $id): bool => Uuid::isValid($id))));

        if ($puzzleIds === []) {
            return [];
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('pl.id');
        $notOwn = '';
        $params = [
            'puzzleIds' => '{' . implode(',', $puzzleIds) . '}',
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'countryJson' => json_encode($viewerCountry ?? '', JSON_THROW_ON_ERROR),
        ];

        if ($viewerId !== null && Uuid::isValid($viewerId)) {
            $notOwn = "\n    AND ssli.player_id <> :viewerId";
            $params['viewerId'] = $viewerId;
        }

        $query = <<<SQL
SELECT
    ssli.puzzle_id,
    COUNT(*) AS offers,
    COUNT(*) FILTER (WHERE (pl.sell_swap_list_settings->'shipping_countries')::jsonb @> :countryJson::jsonb) AS ships_to_viewer
FROM sell_swap_list_item ssli
JOIN puzzle p ON p.id = ssli.puzzle_id
JOIN player pl ON pl.id = ssli.player_id
WHERE ssli.puzzle_id = ANY(:puzzleIds)
    AND ssli.published_on_marketplace = true
    AND ssli.reserved = false
    AND (p.hide_until IS NULL OR p.hide_until <= :now::timestamp){$notOwn}{$notHidden}
GROUP BY ssli.puzzle_id
SQL;

        /** @var list<array{puzzle_id: string, offers: int, ships_to_viewer: int}> $rows */
        $rows = $this->database->executeQuery($query, $params)->fetchAllAssociative();

        $offers = [];

        foreach ($rows as $row) {
            $offers[$row['puzzle_id']] = new WishlistPuzzleOffers(
                puzzleId: $row['puzzle_id'],
                offers: $row['offers'],
                shipsToViewer: $viewerCountry !== null ? $row['ships_to_viewer'] : null,
            );
        }

        return $offers;
    }
}
