<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerMembership;

readonly final class GetPlayerMembership
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws MembershipNotFound
     */
    public function byId(string $playerId): PlayerMembership
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    stripe_subscription_id,
    ends_at,
    billing_period_ends_at
FROM membership
WHERE membership.player_id = :playerId
SQL;

        /**
         * @var false|array{
         *     stripe_subscription_id: null|string,
         *     ends_at: null|string,
         *     billing_period_ends_at: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new MembershipNotFound();
        }

        return PlayerMembership::fromDatabaseRow($row);
    }
}
