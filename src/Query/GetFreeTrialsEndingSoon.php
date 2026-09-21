<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

readonly final class GetFreeTrialsEndingSoon
{
    public const int REMIND_DAYS_BEFORE_END = 3;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Running free trials in their last days that were not reminded yet. Left out: players who already
     * subscribed, and those holding a grant that outlives the trial (a voucher claimed during it) -
     * for them nothing ends with the trial.
     *
     * @return list<string> membership ids
     */
    public function membershipIdsToRemind(DateTimeImmutable $now): array
    {
        $query = <<<SQL
SELECT membership.id
FROM membership
INNER JOIN player ON player.id = membership.player_id
WHERE membership.trial_ends_at > :now
    AND membership.trial_ends_at <= :remindBefore
    AND membership.trial_ending_reminder_sent_at IS NULL
    AND membership.stripe_subscription_id IS NULL
    AND membership.granted_until <= membership.trial_ends_at
    AND player.email IS NOT NULL
ORDER BY membership.trial_ends_at
SQL;

        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery($query, [
                'now' => $now->format('Y-m-d H:i:s'),
                'remindBefore' => $now->modify('+' . self::REMIND_DAYS_BEFORE_END . ' days')->format('Y-m-d H:i:s'),
            ])
            ->fetchFirstColumn();

        return $ids;
    }
}
