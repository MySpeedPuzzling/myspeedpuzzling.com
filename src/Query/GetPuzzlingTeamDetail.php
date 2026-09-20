<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzlingTeamNotFound;
use SpeedPuzzling\Web\Results\PuzzlingTeamDetail;
use SpeedPuzzling\Web\Results\PuzzlingTeamMemberView;
use SpeedPuzzling\Web\Results\PuzzlingTeamTime;
use SpeedPuzzling\Web\Results\RelatedPuzzlingTeam;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The public page of a pair/team (docs/features/pairs-and-teams/README.md).
 *
 * Visibility follows the results themselves: a team with a player the viewer has hidden does not
 * exist for them - unless they are one of its members, whose own history stays whole
 * (HiddenPlayers::sqlExcludeTeam does the same for the times). Private members are listed, masked.
 */
readonly final class GetPuzzlingTeamDetail
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @throws PuzzlingTeamNotFound
     */
    public function byId(string $teamId, null|string $viewerPlayerId): PuzzlingTeamDetail
    {
        if (Uuid::isValid($teamId) === false) {
            throw new PuzzlingTeamNotFound();
        }

        /** @var array{id: string, name: null|string, size: int}|false $team */
        $team = $this->database->fetchAssociative('SELECT id, name, size FROM puzzling_team WHERE id = :id', ['id' => $teamId]);

        if ($team === false) {
            throw new PuzzlingTeamNotFound();
        }

        $members = $this->membersOf([$teamId])[$teamId] ?? [];
        $detail = new PuzzlingTeamDetail($team['id'], $team['name'], $team['size'], $members);

        if ($detail->hasMember($viewerPlayerId) === false) {
            foreach ($members as $member) {
                if ($this->hiddenPlayers->isHidden($member->playerId)) {
                    throw new PuzzlingTeamNotFound();
                }
            }
        }

        return $detail;
    }

    /**
     * @return list<PuzzlingTeamTime>
     */
    public function times(string $teamId, int $limit = 200): array
    {
        $query = <<<SQL
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
FROM puzzle_solving_time time
INNER JOIN puzzle ON puzzle.id = time.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE time.puzzling_team_id = :teamId
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= CAST(:now AS TIMESTAMP))
ORDER BY solved_at DESC, time.id
LIMIT :limit
SQL;

        /**
         * @var list<array{
         *     time_id: string,
         *     seconds_to_solve: null|int,
         *     solved_at: string,
         *     first_attempt: bool,
         *     unboxed: bool,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     pieces_count: int,
         *     puzzle_image: null|string,
         *     manufacturer_name: string,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative($query, [
            'teamId' => $teamId,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ]);

        return array_map(static fn(array $row): PuzzlingTeamTime => new PuzzlingTeamTime(
            timeId: $row['time_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            puzzleImage: $row['puzzle_image'],
            time: $row['seconds_to_solve'],
            solvedAt: new DateTimeImmutable($row['solved_at']),
            firstAttempt: $row['first_attempt'],
            unboxed: $row['unboxed'],
        ), $rows);
    }

    /**
     * The same people with somebody more, or fewer: "Family without Eva" is another team, and this is
     * where the two find each other. Only teams that have results.
     *
     * @return list<RelatedPuzzlingTeam>
     */
    public function relatedTeams(PuzzlingTeamDetail $team, null|string $viewerPlayerId): array
    {
        $memberPlayerIds = array_values(array_filter(array_map(
            static fn(PuzzlingTeamMemberView $member): null|string => $member->playerId,
            $team->members,
        )));

        if ($memberPlayerIds === []) {
            return [];
        }

        // Candidates share a registered member (index on player_id); every pair/team has at least one,
        // so no sub- or superset can be missed
        $query = <<<SQL
SELECT other.id, other.name, other.size, stats.times_count
FROM puzzling_team other
INNER JOIN LATERAL (
    SELECT COUNT(*) AS times_count FROM puzzle_solving_time WHERE puzzling_team_id = other.id
) stats ON TRUE
WHERE other.id IN (SELECT team_id FROM puzzling_team_member WHERE player_id IN (:memberPlayerIds))
    AND other.id <> :teamId
    AND stats.times_count > 0
    AND (
        NOT EXISTS (
            SELECT 1 FROM puzzling_team_member mine
            WHERE mine.team_id = :teamId
                AND NOT EXISTS (SELECT 1 FROM puzzling_team_member theirs WHERE theirs.team_id = other.id AND theirs.member_key = mine.member_key)
        )
        OR NOT EXISTS (
            SELECT 1 FROM puzzling_team_member theirs
            WHERE theirs.team_id = other.id
                AND NOT EXISTS (SELECT 1 FROM puzzling_team_member mine WHERE mine.team_id = :teamId AND mine.member_key = theirs.member_key)
        )
    )
ORDER BY stats.times_count DESC, other.id
LIMIT 12
SQL;

        /** @var list<array{id: string, name: null|string, size: int, times_count: int}> $rows */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['teamId' => $team->teamId, 'memberPlayerIds' => $memberPlayerIds],
            ['memberPlayerIds' => ArrayParameterType::STRING],
        );

        $members = $this->membersOf(array_column($rows, 'id'));
        $related = [];

        foreach ($rows as $row) {
            $teamMembers = $members[$row['id']] ?? [];
            $viewerIsMember = $viewerPlayerId !== null && in_array($viewerPlayerId, array_map(static fn(PuzzlingTeamMemberView $member): null|string => $member->playerId, $teamMembers), true);

            if ($viewerIsMember === false) {
                foreach ($teamMembers as $member) {
                    if ($this->hiddenPlayers->isHidden($member->playerId)) {
                        continue 2;
                    }
                }
            }

            $related[] = new RelatedPuzzlingTeam($row['id'], $row['name'], $row['size'], $row['times_count'], $teamMembers);
        }

        return $related;
    }

    /**
     * @param list<string> $teamIds
     * @return array<string, list<PuzzlingTeamMemberView>>
     */
    private function membersOf(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('player');

        $query = <<<SQL
SELECT
    member.team_id,
    member.player_id,
    member.guest_name,
    player.code AS player_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE player.name END AS player_name,
    CASE WHEN {$isPrivate} THEN NULL ELSE player.country END AS player_country,
    COALESCE({$isPrivate}, FALSE) AS is_private
FROM puzzling_team_member member
LEFT JOIN player ON player.id = member.player_id
WHERE member.team_id IN (:teamIds)
ORDER BY member.team_id, member.position
SQL;

        /**
         * @var list<array{
         *     team_id: string,
         *     player_id: null|string,
         *     guest_name: null|string,
         *     player_code: null|string,
         *     player_name: null|string,
         *     player_country: null|string,
         *     is_private: bool,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative($query, ['teamIds' => $teamIds], ['teamIds' => ArrayParameterType::STRING]);

        $members = [];

        foreach ($rows as $row) {
            $members[$row['team_id']][] = new PuzzlingTeamMemberView(
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'] !== null ? strtoupper($row['player_code']) : null,
                playerCountry: CountryCode::fromCode($row['player_country']),
                guestName: $row['guest_name'],
                isPrivate: $row['is_private'],
            );
        }

        return $members;
    }
}
