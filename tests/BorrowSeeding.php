<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;

/**
 * Isolated owners, collections, wishlists, listings and blocks for the wishlist availability and "ask to borrow" tests
 * (docs/features/wishlist-availability.md) - the fixtures share their players and puzzles with dozens of tests. Plain SQL
 * on top of ComparisonSeeding's players and puzzles; DAMA rolls everything back with the test.
 */
trait BorrowSeeding
{
    use ComparisonSeeding;

    protected function seedFavorite(string $playerId, string $favoriteId): void
    {
        $this->borrowDatabase()->executeStatement(
            "UPDATE player SET favorite_players = (favorite_players::jsonb || jsonb_build_array(:favoriteId::text))::json WHERE id = :playerId",
            ['playerId' => $playerId, 'favoriteId' => $favoriteId],
        );
    }

    protected function seedCollectionVisibility(string $playerId, bool $public): void
    {
        $this->borrowDatabase()->executeStatement(
            'UPDATE player SET puzzle_collection_visibility = :visibility WHERE id = :playerId',
            ['playerId' => $playerId, 'visibility' => $public ? 'public' : 'private'],
        );
    }

    protected function seedCustomCollection(string $playerId, bool $public): string
    {
        $id = Uuid::uuid7()->toString();
        $now = TestClock::SQL_NOW;

        $this->borrowDatabase()->executeStatement(
            "INSERT INTO collection (id, name, visibility, created_at, player_id) VALUES (:id, 'Shelf', :visibility, {$now}, :playerId)",
            ['id' => $id, 'visibility' => $public ? 'public' : 'private', 'playerId' => $playerId],
        );

        return $id;
    }

    /**
     * A puzzle in the player's collection - the system one unless a collection id is given
     */
    protected function seedOwned(string $playerId, string $puzzleId, null|string $collectionId = null): void
    {
        $now = TestClock::SQL_NOW;

        $this->borrowDatabase()->executeStatement(
            "INSERT INTO collection_item (id, added_at, collection_id, player_id, puzzle_id) VALUES (:id, {$now}, :collectionId, :playerId, :puzzleId)",
            ['id' => Uuid::uuid7()->toString(), 'collectionId' => $collectionId, 'playerId' => $playerId, 'puzzleId' => $puzzleId],
        );
    }

    protected function seedWished(string $playerId, string $puzzleId): void
    {
        $now = TestClock::SQL_NOW;

        $this->borrowDatabase()->executeStatement(
            "INSERT INTO wish_list_item (id, added_at, player_id, puzzle_id) VALUES (:id, {$now}, :playerId, :puzzleId)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'puzzleId' => $puzzleId],
        );
    }

    protected function seedLentOut(string $ownerId, string $puzzleId, string $holderName = 'A friend'): void
    {
        $now = TestClock::SQL_NOW;

        $this->borrowDatabase()->executeStatement(
            "INSERT INTO lent_puzzle (id, lent_at, puzzle_id, owner_player_id, current_holder_name) VALUES (:id, {$now}, :puzzleId, :ownerId, :holder)",
            ['id' => Uuid::uuid7()->toString(), 'puzzleId' => $puzzleId, 'ownerId' => $ownerId, 'holder' => $holderName],
        );
    }

    protected function seedBlock(string $blockerId, string $blockedId): void
    {
        $now = TestClock::SQL_NOW;

        $this->borrowDatabase()->executeStatement(
            "INSERT INTO user_block (id, blocked_at, blocker_id, blocked_id) VALUES (:id, {$now}, :blocker, :blocked)",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }

    protected function seedAllowedViewer(string $ownerId, string $viewerId): void
    {
        $now = TestClock::SQL_NOW;

        $this->borrowDatabase()->executeStatement(
            "INSERT INTO private_profile_viewer (id, added_at, owner_id, viewer_id) VALUES (:id, {$now}, :owner, :viewer)",
            ['id' => Uuid::uuid7()->toString(), 'owner' => $ownerId, 'viewer' => $viewerId],
        );
    }

    /**
     * @param list<string> $shipsTo
     */
    protected function seedListing(
        string $sellerId,
        string $puzzleId,
        bool $reserved = false,
        bool $published = true,
        array $shipsTo = [],
    ): string {
        $id = Uuid::uuid7()->toString();
        $now = TestClock::SQL_NOW;

        $this->borrowDatabase()->executeStatement(
            'UPDATE player SET sell_swap_list_settings = :settings WHERE id = :sellerId',
            ['sellerId' => $sellerId, 'settings' => json_encode(['shipping_countries' => $shipsTo, 'currency' => 'EUR'], JSON_THROW_ON_ERROR)],
        );

        $this->borrowDatabase()->executeStatement(
            "INSERT INTO sell_swap_list_item (id, listing_type, price, condition, added_at, player_id, puzzle_id, reserved, published_on_marketplace)
             VALUES (:id, 'sell', 10, 'like_new', {$now}, :sellerId, :puzzleId, :reserved, :published)",
            ['id' => $id, 'sellerId' => $sellerId, 'puzzleId' => $puzzleId, 'reserved' => $reserved, 'published' => $published],
            ['reserved' => ParameterType::BOOLEAN, 'published' => ParameterType::BOOLEAN],
        );

        return $id;
    }

    private function borrowDatabase(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
