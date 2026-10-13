<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\StudioInviteStatus;

/**
 * The studio's invitations of an online round as the moderators' console and the host's studio page show them
 * (docs/features/online-competitions/contracts/phase-1b.md §4, `online_studio` asked for JSON): every participant's
 * latest invitation in the round - waiting for the answer, on air, or how it ended - with their name. Its status as of
 * $now: an unanswered invitation runs out after StudioInvite::EXPIRES_AFTER_SECONDS (StudioInvite::statusAt()). One
 * statement, whatever the number of invitations.
 *
 * @phpstan-type StudioInviteRow array{
 *     id: string,
 *     participantId: string,
 *     name: string,
 *     country: null|string,
 *     status: string,
 *     invitedAtMs: int,
 *     expiresAtMs: int,
 *     answeredAtMs: null|int,
 *     onAirFromMs: null|int,
 *     onAirToMs: null|int,
 * }
 */
readonly final class GetStudioInvites
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<StudioInviteRow> by name
     */
    public function latestOfRound(string $roundId, DateTimeImmutable $now): array
    {
        if (Uuid::isValid($roundId) === false) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT latest.*
FROM (
    SELECT DISTINCT ON (si.participant_id)
        si.id,
        si.participant_id,
        cp.name,
        cp.country,
        si.status,
        (EXTRACT(EPOCH FROM si.invited_at) * 1000)::bigint AS invited_at_ms,
        (EXTRACT(EPOCH FROM si.answered_at) * 1000)::bigint AS answered_at_ms,
        (EXTRACT(EPOCH FROM si.on_air_from) * 1000)::bigint AS on_air_from_ms,
        (EXTRACT(EPOCH FROM si.on_air_to) * 1000)::bigint AS on_air_to_ms
    FROM studio_invite si
    INNER JOIN competition_participant cp ON cp.id = si.participant_id
    WHERE si.round_id = :roundId
    ORDER BY si.participant_id, si.invited_at DESC, si.id DESC
) latest
ORDER BY latest.name, latest.participant_id
SQL,
            ['roundId' => $roundId],
        );

        $nowMs = RoundClock::milliseconds($now);

        return array_map(static function (array $row) use ($nowMs): array {
            $invitedAtMs = self::milliseconds($row['invited_at_ms']) ?? 0;
            $expiresAtMs = $invitedAtMs + StudioInvite::EXPIRES_AFTER_SECONDS * 1000;
            $status = self::text($row['status']);

            // As StudioInvite::statusAt(): nobody answered in time
            if ($status === StudioInviteStatus::Invited->value && $nowMs >= $expiresAtMs) {
                $status = StudioInviteStatus::Expired->value;
            }

            $country = self::text($row['country']);

            return [
                'id' => self::text($row['id']),
                'participantId' => self::text($row['participant_id']),
                'name' => self::text($row['name']),
                'country' => $country === '' ? null : $country,
                'status' => $status,
                'invitedAtMs' => $invitedAtMs,
                'expiresAtMs' => $expiresAtMs,
                'answeredAtMs' => self::milliseconds($row['answered_at_ms']),
                'onAirFromMs' => self::milliseconds($row['on_air_from_ms']),
                'onAirToMs' => self::milliseconds($row['on_air_to_ms']),
            ];
        }, $rows);
    }

    private static function milliseconds(mixed $value): null|int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
