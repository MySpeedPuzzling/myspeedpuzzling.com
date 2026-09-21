<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * Finds the pair/team a group of puzzlers is, creating it the first time these exact people meet.
 *
 * Creation goes through INSERT … ON CONFLICT DO NOTHING rather than the unit of work: two members
 * saving the first time of a brand new team at the same moment must not fail each other - the
 * slower transaction waits on the unique key, inserts nothing and reads the winner's row.
 */
readonly final class PuzzlingTeamResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|string $usedByPlayerId Whoever is adding/editing the time: using a pair/team again takes it
     *                                    out of their archive (PuzzlingTeamArchive) - within the lookup, at no extra query
     */
    public function resolve(null|PuzzlersGroup $group, null|string $preparedByPlayerId = null, null|string $usedByPlayerId = null): null|PuzzlingTeam
    {
        if ($group === null) {
            return null;
        }

        $composition = TeamComposition::fromGroup($group);
        $connection = $this->entityManager->getConnection();

        if ($usedByPlayerId === null) {
            $teamId = $connection->fetchOne(
                'SELECT id FROM puzzling_team WHERE composition_key = :key',
                ['key' => $composition->key],
            );
        } else {
            $teamId = $connection->fetchOne(
                <<<SQL
WITH unarchived AS (
    DELETE FROM puzzling_team_archive archive
    USING puzzling_team team
    WHERE team.composition_key = :key AND archive.team_id = team.id AND archive.player_id = :playerId
)
SELECT id FROM puzzling_team WHERE composition_key = :key
SQL,
                ['key' => $composition->key, 'playerId' => $usedByPlayerId],
            );
        }

        if (is_string($teamId) === false) {
            $teamId = $this->create($composition, $preparedByPlayerId);
        }

        $team = $this->entityManager->getReference(PuzzlingTeam::class, Uuid::fromString($teamId));
        assert($team !== null);

        return $team;
    }

    /**
     * Team and members in one statement: the members are inserted only by whoever really created the
     * team, and a new team costs a single round trip.
     */
    private function create(TeamComposition $composition, null|string $preparedByPlayerId): string
    {
        $connection = $this->entityManager->getConnection();
        $teamId = Uuid::uuid7()->toString();

        $memberRows = [];
        $parameters = [
            'id' => $teamId,
            'key' => $composition->key,
            'size' => $composition->size(),
            'createdAt' => $this->clock->now()->format('Y-m-d H:i:s'),
            'preparedBy' => $preparedByPlayerId,
        ];

        foreach ($composition->members as $index => $member) {
            $memberRows[] = "(CAST(:memberId{$index} AS UUID), :memberKey{$index}, CAST(:playerId{$index} AS UUID), :guestName{$index}, CAST(:position{$index} AS SMALLINT))";
            $parameters["memberId{$index}"] = Uuid::uuid7()->toString();
            $parameters["memberKey{$index}"] = $member->memberKey;
            $parameters["playerId{$index}"] = $member->playerId;
            $parameters["guestName{$index}"] = $member->guestName;
            $parameters["position{$index}"] = $member->position;
        }

        $memberValues = implode(",\n        ", $memberRows);

        $insertedMembers = $connection->executeStatement(
            <<<SQL
WITH new_team AS (
    INSERT INTO puzzling_team (id, composition_key, size, created_at, prepared_by_id)
    VALUES (:id, :key, :size, :createdAt, :preparedBy)
    ON CONFLICT (composition_key) DO NOTHING
    RETURNING id
)
INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position)
SELECT member.id, new_team.id, member.member_key, member.player_id, member.guest_name, member.position
FROM new_team
CROSS JOIN (
    VALUES
        {$memberValues}
) AS member (id, member_key, player_id, guest_name, position)
SQL,
            $parameters,
        );

        if ($insertedMembers > 0) {
            return $teamId;
        }

        // Nothing inserted: somebody else created the very same team meanwhile - theirs is the one
        /** @var string $teamId */
        $teamId = $connection->fetchOne(
            'SELECT id FROM puzzling_team WHERE composition_key = :key',
            ['key' => $composition->key],
        );

        return $teamId;
    }
}
