<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ManageableCompetitionParticipant;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\ParticipantSource;

readonly final class GetCompetitionParticipantsForManagement
{
    public function __construct(
        private Connection $database,
        private GetCompetitionRounds $getCompetitionRounds,
    ) {
    }

    /**
     * @return array<ManageableCompetitionParticipant>
     */
    public function all(string $competitionId, bool $includeDeleted = false): array
    {
        $query = <<<SQL
SELECT
    cp.id AS participant_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    cp.external_id,
    cp.source,
    cp.deleted_at,
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country
FROM competition_participant cp
LEFT JOIN player p ON p.id = cp.player_id
WHERE cp.competition_id = :competitionId
SQL;

        if ($includeDeleted === false) {
            $query .= ' AND cp.deleted_at IS NULL';
        }

        $query .= ' ORDER BY cp.name';

        $rows = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
            ])
            ->fetchAllAssociative();

        $participantRounds = $this->getCompetitionRounds->forAllCompetitionParticipants($competitionId);

        return array_map(static function (array $row) use ($participantRounds): ManageableCompetitionParticipant {
            /**
             * @var array{
             *     participant_id: string,
             *     participant_name: string,
             *     participant_country: null|string,
             *     external_id: null|string,
             *     source: string,
             *     deleted_at: null|string,
             *     player_id: null|string,
             *     player_name: null|string,
             *     player_code: null|string,
             *     player_country: null|string,
             * } $row
             */

            return new ManageableCompetitionParticipant(
                participantId: $row['participant_id'],
                participantName: $row['participant_name'],
                participantCountry: CountryCode::fromCode($row['participant_country']),
                externalId: $row['external_id'],
                source: ParticipantSource::from($row['source']),
                deletedAt: $row['deleted_at'] !== null ? new DateTimeImmutable($row['deleted_at']) : null,
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                playerCountry: CountryCode::fromCode($row['player_country']),
                roundIds: $participantRounds[$row['participant_id']] ?? [],
            );
        }, $rows);
    }
}
