<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

/**
 * Builds the PuzzlersGroup for a solving time from a CompetitionTeam roster.
 *
 * Connected members become real Puzzlers (player id + display name), unconnected
 * ones stay name-only — the same shape ad-hoc group times use platform-wide.
 */
readonly final class CompetitionTeamGroupBuilder
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param array<string> $treatAsNameOnlyPlayerIds player ids whose entries should be downgraded to name-only (un-claim)
     */
    public function buildGroup(string $teamId, array $treatAsNameOnlyPlayerIds = []): null|PuzzlersGroup
    {
        $query = <<<SQL
SELECT cp.name AS participant_name, cp.country AS participant_country,
    p.id AS player_id, p.name AS player_name, p.code AS player_code, p.country AS player_country, p.is_private
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
LEFT JOIN player p ON p.id = cp.player_id
WHERE cpr.team_id = :teamId
ORDER BY cp.name
SQL;

        /** @var array<array{participant_name: string, participant_country: null|string, player_id: null|string, player_name: null|string, player_code: null|string, player_country: null|string, is_private: null|bool|string}> $rows */
        $rows = $this->database->executeQuery($query, ['teamId' => $teamId])->fetchAllAssociative();

        if ($rows === []) {
            return null;
        }

        $puzzlers = [];

        foreach ($rows as $row) {
            $connected = $row['player_id'] !== null && !in_array($row['player_id'], $treatAsNameOnlyPlayerIds, true);

            $isPrivate = $row['is_private'];
            if (is_string($isPrivate)) {
                $isPrivate = $isPrivate === 't' || $isPrivate === '1' || $isPrivate === 'true';
            }

            if ($connected) {
                $puzzlers[] = new Puzzler(
                    playerId: $row['player_id'],
                    playerName: $row['player_name'] ?? $row['participant_name'],
                    playerCode: $row['player_code'] !== null ? strtoupper($row['player_code']) : null,
                    playerCountry: CountryCode::fromCode($row['player_country']),
                    isPrivate: $isPrivate ?? false,
                );
            } else {
                $puzzlers[] = new Puzzler(
                    playerId: null,
                    playerName: $row['participant_name'],
                    playerCode: null,
                    playerCountry: CountryCode::fromCode($row['participant_country']),
                    isPrivate: false,
                );
            }
        }

        return new PuzzlersGroup(teamId: null, puzzlers: $puzzlers);
    }
}
