<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * What the internal API answers about a player's address: whether, why and since when nothing (or no notification) is
 * sent to it. Null when the address is not suppressed or the player has no account.
 */
readonly final class GetPlayerEmailSuppression
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return null|array{email: string, source: string, reason: null|string, suppressedAt: string, bounceSubType: null|string, diagnosticCode: null|string}
     */
    public function ofPlayer(string $playerId): null|array
    {
        $query = <<<SQL
SELECT email_suppression.email, email_suppression.source, email_suppression.reason,
       email_suppression.suppressed_at, email_suppression.bounce_sub_type, email_suppression.diagnostic_code
FROM player
INNER JOIN user_account ON user_account.user_id = player.user_id
INNER JOIN email_suppression ON email_suppression.email = user_account.email
WHERE player.id = :playerId
SQL;

        /** @var false|array{email: string, source: string, reason: null|string, suppressed_at: string, bounce_sub_type: null|string, diagnostic_code: null|string} $row */
        $row = $this->database->executeQuery($query, ['playerId' => $playerId])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'email' => $row['email'],
            'source' => $row['source'],
            'reason' => $row['reason'],
            'suppressedAt' => (new \DateTimeImmutable($row['suppressed_at']))->format(\DateTimeInterface::ATOM),
            'bounceSubType' => $row['bounce_sub_type'],
            'diagnosticCode' => $row['diagnostic_code'],
        ];
    }
}
