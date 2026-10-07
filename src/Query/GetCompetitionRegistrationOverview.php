<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\RegistrationOverview;
use SpeedPuzzling\Web\Value\RegistrationStatus;

readonly final class GetCompetitionRegistrationOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forCompetition(string $competitionId, null|string $playerId): RegistrationOverview
    {
        $query = <<<SQL
SELECT registration_managed, capacity, registration_opens_at, registration_closes_at, entry_fee_text, payment_instructions
FROM competition
WHERE id = :competitionId
SQL;

        /** @var false|array{registration_managed: bool|string, capacity: null|int|string, registration_opens_at: null|string, registration_closes_at: null|string, entry_fee_text: null|string, payment_instructions: null|string} $competition */
        $competition = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
        ])->fetchAssociative();

        if ($competition === false) {
            return new RegistrationOverview(
                registrationManaged: false,
                capacity: null,
                registrationOpensAt: null,
                registrationClosesAt: null,
                entryFeeText: null,
                paymentInstructions: null,
                spotsTaken: 0,
                waitlistedCount: 0,
                playerParticipantId: null,
                playerStatus: null,
                playerWaitlistPosition: null,
            );
        }

        $registrationManaged = $competition['registration_managed'];
        if (is_string($registrationManaged)) {
            $registrationManaged = $registrationManaged === 't' || $registrationManaged === '1' || $registrationManaged === 'true';
        }

        $counts = $this->countByStatus($competitionId);
        $playerRegistration = $playerId !== null ? $this->playerRegistration($competitionId, $playerId) : null;

        return new RegistrationOverview(
            registrationManaged: $registrationManaged,
            capacity: $competition['capacity'] !== null ? (int) $competition['capacity'] : null,
            registrationOpensAt: $competition['registration_opens_at'] !== null ? new DateTimeImmutable($competition['registration_opens_at']) : null,
            registrationClosesAt: $competition['registration_closes_at'] !== null ? new DateTimeImmutable($competition['registration_closes_at']) : null,
            entryFeeText: $competition['entry_fee_text'],
            paymentInstructions: $competition['payment_instructions'],
            spotsTaken: $counts['reserved'] + $counts['paid'],
            waitlistedCount: $counts['waitlisted'],
            playerParticipantId: $playerRegistration['participantId'] ?? null,
            playerStatus: $playerRegistration['status'] ?? null,
            playerWaitlistPosition: $playerRegistration['waitlistPosition'] ?? null,
        );
    }

    /**
     * Active registrations occupying capacity: not deleted, reserved or paid.
     * Participants without any registration status (legacy, organizer-imported
     * before management was enabled) count as reserved.
     */
    public function countActiveRegistrations(string $competitionId): int
    {
        $query = <<<SQL
SELECT COUNT(*)
FROM competition_participant
WHERE competition_id = :competitionId
AND deleted_at IS NULL
AND (registration_status IS NULL OR registration_status IN (:reserved, :paid))
SQL;

        /** @var int|string $count */
        $count = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'reserved' => RegistrationStatus::Reserved->value,
            'paid' => RegistrationStatus::Paid->value,
        ])->fetchOne();

        return (int) $count;
    }

    /**
     * @return array{reserved: int, paid: int, waitlisted: int}
     */
    public function countByStatus(string $competitionId): array
    {
        $query = <<<SQL
SELECT registration_status, COUNT(*) AS count
FROM competition_participant
WHERE competition_id = :competitionId
AND deleted_at IS NULL
GROUP BY registration_status
SQL;

        /** @var array<array{registration_status: null|string, count: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
        ])->fetchAllAssociative();

        $counts = [
            'reserved' => 0,
            'paid' => 0,
            'waitlisted' => 0,
        ];

        foreach ($rows as $row) {
            // Legacy participants without status count as reserved
            $status = $row['registration_status'] ?? RegistrationStatus::Reserved->value;

            if (array_key_exists($status, $counts)) {
                $counts[$status] += (int) $row['count'];
            }
        }

        return $counts;
    }

    /**
     * Oldest waitlisted registration (FIFO by registered_at).
     */
    public function nextWaitlistedParticipantId(string $competitionId): null|string
    {
        $query = <<<SQL
SELECT id
FROM competition_participant
WHERE competition_id = :competitionId
AND deleted_at IS NULL
AND registration_status = :waitlisted
ORDER BY registered_at ASC NULLS LAST
LIMIT 1
SQL;

        /** @var false|string $result */
        $result = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'waitlisted' => RegistrationStatus::Waitlisted->value,
        ])->fetchOne();

        return $result !== false ? $result : null;
    }

    /**
     * @return null|array{participantId: string, status: null|RegistrationStatus, waitlistPosition: null|int}
     */
    public function playerRegistration(string $competitionId, string $playerId): null|array
    {
        $query = <<<SQL
SELECT id, registration_status
FROM competition_participant
WHERE competition_id = :competitionId
AND player_id = :playerId
AND deleted_at IS NULL
LIMIT 1
SQL;

        /** @var false|array{id: string, registration_status: null|string} $row */
        $row = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $status = $row['registration_status'] !== null
            ? RegistrationStatus::from($row['registration_status'])
            : null;

        return [
            'participantId' => $row['id'],
            'status' => $status,
            'waitlistPosition' => $status === RegistrationStatus::Waitlisted
                ? $this->waitlistPosition($competitionId, $row['id'])
                : null,
        ];
    }

    public function waitlistPosition(string $competitionId, string $participantId): null|int
    {
        $query = <<<SQL
SELECT position FROM (
    SELECT id, ROW_NUMBER() OVER (ORDER BY registered_at ASC NULLS LAST) AS position
    FROM competition_participant
    WHERE competition_id = :competitionId
    AND deleted_at IS NULL
    AND registration_status = :waitlisted
) positions
WHERE id = :participantId
SQL;

        /** @var false|int|string $result */
        $result = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'participantId' => $participantId,
            'waitlisted' => RegistrationStatus::Waitlisted->value,
        ])->fetchOne();

        return $result !== false ? (int) $result : null;
    }
}
