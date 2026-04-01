<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerOAuth2Consent;

/**
 * @phpstan-import-type PlayerOAuth2ConsentRow from PlayerOAuth2Consent
 */
readonly final class GetPlayerOAuth2Consents
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PlayerOAuth2Consent>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    c.id,
    c.client_identifier,
    cl.name AS client_name,
    c.scopes,
    c.consented_at,
    c.last_used_at
FROM oauth2_user_consent c
INNER JOIN oauth2_client cl ON cl.identifier = c.client_identifier
WHERE c.player_id = :playerId
ORDER BY c.consented_at DESC
SQL;

        /** @var array<PlayerOAuth2ConsentRow> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): PlayerOAuth2Consent => PlayerOAuth2Consent::fromDatabaseRow($row),
            $rows,
        );
    }
}
