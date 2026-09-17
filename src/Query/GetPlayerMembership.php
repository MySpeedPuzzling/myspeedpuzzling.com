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
    membership.stripe_subscription_id,
    membership.ends_at,
    membership.billing_period_ends_at,
    membership.granted_until,
    discount_voucher.percentage_discount AS discount_percent,
    discount_voucher.code AS discount_voucher_code
FROM membership
LEFT JOIN voucher discount_voucher
    ON discount_voucher.stripe_coupon_id = membership.stripe_discount_coupon_id
    AND discount_voucher.voucher_type = 'percentage_discount'
WHERE membership.player_id = :playerId
LIMIT 1
SQL;

        /**
         * @var false|array{
         *     stripe_subscription_id: null|string,
         *     ends_at: null|string,
         *     billing_period_ends_at: null|string,
         *     granted_until: null|string,
         *     discount_percent: null|int,
         *     discount_voucher_code: null|string,
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
