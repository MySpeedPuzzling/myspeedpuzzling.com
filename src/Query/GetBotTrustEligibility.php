<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * May this account carry the bot-blocker's __bb_trust cookie (BotTrustCookieSubscriber)? The cookie lets a browser
 * skip every bot check at the edge, and registration signs a new account in at once without verifying its e-mail -
 * so a bare account is not enough: one throwaway account would be a months-long bypass for a scraper.
 *
 * Eligible is an account with ANY of: a verified e-mail, a social login, a solving time of its player, or an age of
 * at least TRUSTED_ACCOUNT_AGE_DAYS. On production (2026-11-16) 43 of the 3,986 accounts active in the last 30 days
 * had none of the four.
 *
 * Asked only when the cookie has to be minted or refreshed, never on every request. Not final: unit tests stub it.
 */
readonly class GetBotTrustEligibility
{
    public const int TRUSTED_ACCOUNT_AGE_DAYS = 14;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $userId user_account.user_id - the security user identifier (player.user_id joins on it)
     */
    public function isEligible(string $userId): bool
    {
        $query = <<<SQL
SELECT
    user_account.email_verified_at IS NOT NULL
    OR user_account.registered_at <= :registeredBefore
    OR EXISTS (SELECT 1 FROM oauth_identity WHERE oauth_identity.user_account_id = user_account.id)
    OR EXISTS (
        SELECT 1
        FROM player
        INNER JOIN puzzle_solving_time ON puzzle_solving_time.player_id = player.id
        WHERE player.user_id = user_account.user_id
    ) AS eligible
FROM user_account
WHERE user_account.user_id = :userId
SQL;

        $eligible = $this->database
            ->executeQuery($query, [
                'userId' => $userId,
                // registered_at is timestamptz - bind the instant with its offset
                'registeredBefore' => $this->clock->now()
                    ->modify(sprintf('-%d days', self::TRUSTED_ACCOUNT_AGE_DAYS))
                    ->format('Y-m-d H:i:sP'),
            ])
            ->fetchOne();

        // No row (an account deleted under a live session) is never eligible
        return $eligible === true;
    }
}
