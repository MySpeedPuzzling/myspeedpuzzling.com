<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Results\ManageableCompetitionParticipant;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;

readonly final class GetCompetitionParticipantsForManagement
{
    private const string SELECT = <<<SQL
SELECT
    cp.id AS participant_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    cp.external_id,
    cp.source,
    cp.deleted_at,
    cp.registration_status,
    cp.registered_at,
    cp.paid_at,
    cp.checked_in_at,
    cp.organizer_note,
    p.id AS player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country
FROM competition_participant cp
LEFT JOIN player p ON p.id = cp.player_id
SQL;

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
        $query = self::SELECT . ' WHERE cp.competition_id = :competitionId';

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

        return array_map(
            static fn (array $row): ManageableCompetitionParticipant => self::hydrate($row, $participantRounds),
            $rows,
        );
    }

    /**
     * One participant of the competition, soft-deleted included. A participant of another
     * competition is not found, so an id from elsewhere is never edited through this one.
     *
     * @throws CompetitionParticipantNotFound
     */
    public function byId(string $competitionId, string $participantId): ManageableCompetitionParticipant
    {
        if (!Uuid::isValid($participantId)) {
            throw new CompetitionParticipantNotFound();
        }

        $row = $this->database
            ->executeQuery(self::SELECT . ' WHERE cp.competition_id = :competitionId AND cp.id = :participantId', [
                'competitionId' => $competitionId,
                'participantId' => $participantId,
            ])
            ->fetchAssociative();

        if ($row === false) {
            throw new CompetitionParticipantNotFound();
        }

        $query = <<<SQL
SELECT cpr.round_id
FROM competition_participant_round cpr
INNER JOIN competition_round cr ON cr.id = cpr.round_id
WHERE cpr.participant_id = :participantId
    AND cr.competition_id = :competitionId
SQL;

        /** @var array<string> $roundIds */
        $roundIds = $this->database
            ->executeQuery($query, [
                'participantId' => $participantId,
                'competitionId' => $competitionId,
            ])
            ->fetchFirstColumn();

        return self::hydrate($row, [$participantId => $roundIds]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string>> $participantRounds
     */
    private static function hydrate(array $row, array $participantRounds): ManageableCompetitionParticipant
    {
        /**
         * @var array{
         *     participant_id: string,
         *     participant_name: string,
         *     participant_country: null|string,
         *     external_id: null|string,
         *     source: string,
         *     deleted_at: null|string,
         *     registration_status: null|string,
         *     registered_at: null|string,
         *     paid_at: null|string,
         *     checked_in_at: null|string,
         *     organizer_note: null|string,
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
            registrationStatus: $row['registration_status'] !== null ? RegistrationStatus::from($row['registration_status']) : null,
            registeredAt: $row['registered_at'] !== null ? new DateTimeImmutable($row['registered_at']) : null,
            paidAt: $row['paid_at'] !== null ? new DateTimeImmutable($row['paid_at']) : null,
            checkedInAt: $row['checked_in_at'] !== null ? new DateTimeImmutable($row['checked_in_at']) : null,
            organizerNote: $row['organizer_note'],
        );
    }
}
