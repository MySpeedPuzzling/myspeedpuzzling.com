<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerProfile;

/**
 * @phpstan-import-type PlayerProfileRow from PlayerProfile
 */
readonly final class GetPlayerProfile
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function byId(string $playerId): PlayerProfile
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    name AS player_name,
    user_id,
    email,
    country,
    city,
    code,
    favorite_players,
    avatar,
    bio,
    facebook,
    instagram,
    stripe_customer_id,
    modal_displayed,
    locale,
    is_admin,
    is_private,
    puzzle_collection_visibility,
    unsolved_puzzles_visibility,
    wish_list_visibility,
    lend_borrow_list_visibility,
    solved_puzzles_visibility,
    sell_swap_list_settings,
    allow_direct_messages,
    COALESCE(membership.ends_at, membership.billing_period_ends_at) AS membership_ends_at
FROM player
LEFT JOIN membership ON membership.player_id = player.id
WHERE player.id = :playerId
SQL;

        /**
         * @var false|PlayerProfileRow $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerProfile::fromDatabaseRow($row, $this->clock->now());
    }

    /**
     * @throws PlayerNotFound
     */
    public function byUserId(string $userId): PlayerProfile
    {
        $query = <<<SQL
SELECT
    player.id AS player_id,
    name AS player_name,
    user_id,
    email,
    country,
    city,
    code,
    favorite_players,
    avatar,
    bio,
    facebook,
    instagram,
    stripe_customer_id,
    modal_displayed,
    locale,
    is_admin,
    is_private,
    puzzle_collection_visibility,
    unsolved_puzzles_visibility,
    wish_list_visibility,
    lend_borrow_list_visibility,
    solved_puzzles_visibility,
    sell_swap_list_settings,
    allow_direct_messages,
    COALESCE(membership.ends_at, membership.billing_period_ends_at) AS membership_ends_at
FROM player
LEFT JOIN membership ON membership.player_id = player.id
WHERE player.user_id = :userId
SQL;

        /**
         * @var false|PlayerProfileRow $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'userId' => $userId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerProfile::fromDatabaseRow($row, $this->clock->now());
    }
}
