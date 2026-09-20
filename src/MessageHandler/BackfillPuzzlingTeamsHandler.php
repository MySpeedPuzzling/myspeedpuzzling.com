<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\BackfillPuzzlingTeams;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Gives group times from before pairs & teams existed their team. One batch per message, so each
 * batch commits on its own and the run can be interrupted and resumed; returns the id of the last
 * time it looked at - null means there is nothing left.
 */
#[AsMessageHandler]
readonly final class BackfillPuzzlingTeamsHandler
{
    public function __construct(
        private Connection $connection,
        private PuzzlingTeamResolver $puzzlingTeamResolver,
    ) {
    }

    public function __invoke(BackfillPuzzlingTeams $message): null|string
    {
        /** @var list<array{id: string, team: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, team FROM puzzle_solving_time WHERE team IS NOT NULL AND puzzling_team_id IS NULL AND id > :afterId ORDER BY id LIMIT :limit',
            [
                'afterId' => $message->afterTimeId ?? '00000000-0000-0000-0000-000000000000',
                'limit' => max(1, $message->batchSize),
            ],
        );

        if ($rows === []) {
            return null;
        }

        $groups = [];
        $playerIds = [];

        foreach ($rows as $row) {
            /** @var array{puzzlers?: list<array{player_id?: null|string, player_name?: null|string}>} $team */
            $team = json_decode($row['team'], true, flags: JSON_THROW_ON_ERROR);
            $groups[$row['id']] = $team['puzzlers'] ?? [];

            foreach ($groups[$row['id']] as $puzzler) {
                if (($puzzler['player_id'] ?? null) !== null) {
                    $playerIds[$puzzler['player_id']] = true;
                }
            }
        }

        /** @var list<string> $existingPlayerIds */
        $existingPlayerIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM player WHERE id IN (:ids)',
            ['ids' => array_keys($playerIds)],
            ['ids' => ArrayParameterType::STRING],
        );
        $existingPlayerIds = array_flip($existingPlayerIds);

        foreach ($groups as $timeId => $rawPuzzlers) {
            $puzzlers = [];

            foreach ($rawPuzzlers as $rawPuzzler) {
                $playerId = $rawPuzzler['player_id'] ?? null;

                // A snapshot may name an account that no longer exists - that person is a guest now
                if ($playerId !== null && isset($existingPlayerIds[$playerId]) === false) {
                    $playerId = null;
                }

                $puzzlers[] = new Puzzler(
                    playerId: $playerId,
                    playerName: $rawPuzzler['player_name'] ?? null,
                    playerCode: null,
                    playerCountry: null,
                    isPrivate: false,
                );
            }

            if ($puzzlers === []) {
                continue;
            }

            $team = $this->puzzlingTeamResolver->resolve(new PuzzlersGroup(null, $puzzlers));
            assert($team !== null);

            $this->connection->executeStatement(
                'UPDATE puzzle_solving_time SET puzzling_team_id = :teamId WHERE id = :id',
                ['teamId' => $team->id->toString(), 'id' => $timeId],
            );
        }

        return $rows[array_key_last($rows)]['id'];
    }
}
