<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\ConversationStatus;

readonly final class HasExistingConversation
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function acceptedBetween(string $playerAId, string $playerBId): bool
    {
        $query = <<<SQL
SELECT COUNT(*)
FROM conversation
WHERE (
    (initiator_id = :playerA AND recipient_id = :playerB)
    OR (initiator_id = :playerB AND recipient_id = :playerA)
)
AND status = :status
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'playerA' => $playerAId,
                'playerB' => $playerBId,
                'status' => ConversationStatus::Accepted->value,
            ])
            ->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }
}
