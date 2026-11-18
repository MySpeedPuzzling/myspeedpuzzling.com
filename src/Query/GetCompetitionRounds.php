<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionRoundInfo;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundBadgeColor;

readonly final class GetCompetitionRounds
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, CompetitionRoundInfo>
     */
    public function ofCompetition(string $competitionId): array
    {
        $query = <<<SQL
SELECT
    id,
    name,
    badge_background_color,
    -- Colour by schedule position, like the round's pill on the event pages, while the chips stay sorted by name
    ROW_NUMBER() OVER (ORDER BY starts_at, id) - 1 AS schedule_position
FROM competition_round
WHERE competition_id = :competitionId
ORDER BY name
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
            ])
            ->fetchAllAssociative();

        $results = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     id: string,
             *     name: string,
             *     badge_background_color: null|string,
             *     schedule_position: int|string,
             * } $row
             */

            $color = RoundBadgeColor::background($row['badge_background_color'], (int) $row['schedule_position']);

            $results[$row['id']] = new CompetitionRoundInfo(
                id: $row['id'],
                name: $row['name'],
                textColor: RoundBadgeColor::text($color),
                color: $color,
            );
        }

        return $results;
    }

    /**
     * @return array<string>
     */
    public function slugsOfCompetition(string $competitionId): array
    {
        /** @var array<string> $slugs */
        $slugs = $this->database
            ->executeQuery(
                'SELECT slug FROM competition_round WHERE competition_id = :competitionId AND slug IS NOT NULL',
                ['competitionId' => $competitionId],
            )
            ->fetchFirstColumn();

        return $slugs;
    }

    /**
     * Names of the other rounds of the competition that have one of these puzzles in the given category, by start (an
     * empty list when there is none). Several rounds of one category may share a puzzle (a team challenge and an open
     * round on one puzzle - docs/features/competitions-management/shared-puzzles-and-divisions.md): a solving time's
     * round then follows from each player's entry (SolvingTimeRoundMatch). Used for the notices that say so, and by the
     * round move, which still refuses to bring a puzzle into a competition that has it in the category already.
     *
     * @param array<string> $puzzleIds
     * @return list<string>
     */
    public function roundsSharingPuzzleInCategory(
        string $competitionId,
        array $puzzleIds,
        RoundCategory $category,
        null|string $exceptRoundId = null,
    ): array {
        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<string> $names */
        $names = $this->database
            ->executeQuery(
                <<<SQL
SELECT cr.name
FROM competition_round cr
WHERE cr.competition_id = :competitionId
    AND cr.category = :category
    AND (CAST(:exceptRoundId AS UUID) IS NULL OR cr.id <> CAST(:exceptRoundId AS UUID))
    AND EXISTS (
        SELECT 1 FROM competition_round_puzzle crp
        WHERE crp.round_id = cr.id
            AND crp.puzzle_id IN (:puzzleIds)
    )
ORDER BY cr.starts_at, cr.name, cr.id
SQL,
                [
                    'competitionId' => $competitionId,
                    'category' => $category->value,
                    'puzzleIds' => $puzzleIds,
                    'exceptRoundId' => $exceptRoundId,
                ],
                ['puzzleIds' => ArrayParameterType::STRING],
            )
            ->fetchFirstColumn();

        return $names;
    }

    public function hasParticipantsAssignedToRounds(string $competitionId): bool
    {
        $query = <<<SQL
SELECT EXISTS (
    SELECT 1
    FROM competition_participant_round
    INNER JOIN competition_round ON competition_participant_round.round_id = competition_round.id
    WHERE competition_round.competition_id = :competitionId
)
SQL;

        return (bool) $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchOne();
    }

    /**
     * @return array<string, array<string>>
     * @param array<string> $roundsFilter
     */
    public function forAllCompetitionParticipants(string $competitionId, array $roundsFilter = []): array
    {
        $queryParams = ['competitionId' => $competitionId];
        $paramTypes = [];

        $query = <<<SQL
SELECT participant_id, round_id
FROM competition_participant_round
INNER JOIN competition_round ON competition_participant_round.round_id = competition_round.id
WHERE competition_round.competition_id = :competitionId
SQL;

        if (count($roundsFilter) > 0) {
            $query .= ' AND competition_participant_round.round_id IN (:rounds)';

            $queryParams['rounds'] = $roundsFilter;
            $paramTypes['rounds'] = ArrayParameterType::STRING;
        }

        $results = [];

        $rows = $this->database
            ->executeQuery($query, $queryParams, $paramTypes)
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            /**
             * @var array{round_id: string, participant_id: string} $row
             */

            $participantId = $row['participant_id'];

            if (!isset($results[$participantId])) {
                $results[$participantId] = [];
            }

            $results[$participantId][] = $row['round_id'];
        }

        return $results;
    }
}
