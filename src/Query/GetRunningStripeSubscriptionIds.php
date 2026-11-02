<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetRunningStripeSubscriptionIds
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Web memberships whose Stripe subscription still runs (not cancelled, or cancelled with days left).
     *
     * @return list<string>
     */
    public function all(\DateTimeImmutable $now): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(<<<SQL
SELECT stripe_subscription_id
FROM membership
WHERE stripe_subscription_id IS NOT NULL
    AND platform = 'web'
    AND (ends_at IS NULL OR ends_at > :now)
ORDER BY created_at
SQL, ['now' => $now->format('Y-m-d H:i:s')])->fetchFirstColumn();

        return $ids;
    }
}
