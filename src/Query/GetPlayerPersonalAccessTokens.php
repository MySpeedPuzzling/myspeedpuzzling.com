<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerPersonalAccessToken;

/**
 * @phpstan-import-type PlayerPersonalAccessTokenRow from PlayerPersonalAccessToken
 */
final readonly class GetPlayerPersonalAccessTokens
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PlayerPersonalAccessToken>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    id,
    name,
    token_prefix,
    created_at,
    last_used_at
FROM personal_access_token
WHERE player_id = :playerId
AND revoked_at IS NULL
ORDER BY created_at DESC
SQL;

        /** @var array<PlayerPersonalAccessTokenRow> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): PlayerPersonalAccessToken => PlayerPersonalAccessToken::fromDatabaseRow($row),
            $rows,
        );
    }
}
