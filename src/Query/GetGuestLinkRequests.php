<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\GuestLinkRequestNotFound;
use SpeedPuzzling\Web\Results\GuestLinkRequestDetail;
use SpeedPuzzling\Web\Results\PuzzlingTeamTime;
use SpeedPuzzling\Web\Services\HiddenPlayers;

readonly final class GetGuestLinkRequests
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * The question as whoever was asked sees it. The two players are no strangers to each other - one
     * claims to have puzzled with the other - so the requester is named as they are, like the sender of a message.
     *
     * @throws GuestLinkRequestNotFound also for anybody but the asked player
     */
    public function forTarget(string $requestId, string $targetPlayerId): GuestLinkRequestDetail
    {
        if (Uuid::isValid($requestId) === false) {
            throw new GuestLinkRequestNotFound();
        }

        // A player blocked after they asked: the question is gone with everything else of theirs
        $requesterNotHidden = $this->hiddenPlayers->sqlExclude('requester.id');

        /** @var array{id: string, requester_id: string, requester_name: null|string, requester_code: string, guest_key: string, guest_name: string, resolved_at: null|string, accepted: null|bool}|false $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT request.id, request.requester_id, requester.name AS requester_name, requester.code AS requester_code,
       request.guest_key, request.guest_name, request.resolved_at, request.accepted
FROM guest_link_request request
INNER JOIN player requester ON requester.id = request.requester_id
WHERE request.id = :id AND request.target_id = :targetId{$requesterNotHidden}
SQL,
            ['id' => $requestId, 'targetId' => $targetPlayerId],
        );

        if ($row === false) {
            throw new GuestLinkRequestNotFound();
        }

        /**
         * @var list<array{
         *     time_id: string, seconds_to_solve: null|int, solved_at: string, first_attempt: bool, unboxed: bool,
         *     puzzle_id: string, puzzle_name: string, pieces_count: int, puzzle_image: null|string, manufacturer_name: string,
         * }> $times
         */
        $times = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    time.id AS time_id,
    time.seconds_to_solve,
    COALESCE(time.finished_at, time.tracked_at) AS solved_at,
    time.first_attempt,
    time.unboxed,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > CAST(:now AS TIMESTAMP) THEN NULL ELSE puzzle.image END AS puzzle_image,
    manufacturer.name AS manufacturer_name
FROM puzzling_team_member guest
INNER JOIN puzzling_team_member requester ON requester.team_id = guest.team_id AND requester.player_id = :requesterId
INNER JOIN puzzle_solving_time time ON time.puzzling_team_id = guest.team_id
INNER JOIN puzzle ON puzzle.id = time.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE guest.member_key = :guestKey AND guest.player_id IS NULL
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= CAST(:now AS TIMESTAMP))
ORDER BY solved_at DESC, time.id
LIMIT 50
SQL,
            [
                'requesterId' => $row['requester_id'],
                'guestKey' => $row['guest_key'],
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        return new GuestLinkRequestDetail(
            requestId: $row['id'],
            requesterId: $row['requester_id'],
            requesterLabel: $row['requester_name'] ?? '#' . strtoupper($row['requester_code']),
            guestName: $row['guest_name'],
            pending: $row['resolved_at'] === null,
            accepted: $row['accepted'],
            results: array_map(static fn(array $time): PuzzlingTeamTime => new PuzzlingTeamTime(
                timeId: $time['time_id'],
                puzzleId: $time['puzzle_id'],
                puzzleName: $time['puzzle_name'],
                manufacturerName: $time['manufacturer_name'],
                piecesCount: $time['pieces_count'],
                puzzleImage: $time['puzzle_image'],
                time: $time['seconds_to_solve'],
                solvedAt: new DateTimeImmutable($time['solved_at']),
                firstAttempt: $time['first_attempt'],
                unboxed: $time['unboxed'],
            ), $times),
        );
    }

    /**
     * Guests the player has an open question about: guest key => who was asked (their player code -
     * what the player typed themselves).
     *
     * @return array<string, string>
     */
    public function pendingOf(string $requesterPlayerId): array
    {
        $targetNotHidden = $this->hiddenPlayers->sqlExclude('target.id');

        /** @var array<string, string> $pending */
        $pending = $this->database->fetchAllKeyValue(
            <<<SQL
SELECT request.guest_key, UPPER(target.code)
FROM guest_link_request request
INNER JOIN player target ON target.id = request.target_id
WHERE request.requester_id = :playerId AND request.resolved_at IS NULL{$targetNotHidden}
SQL,
            ['playerId' => $requesterPlayerId],
        );

        return $pending;
    }
}
