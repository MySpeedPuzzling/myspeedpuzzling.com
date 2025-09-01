<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CompetitionRoundInfo;

readonly final class GetCompetitionRounds
{
    private const array COLORS = ['#E6194B', '#3CB44B', '#FFE119', '#0082C8', '#F58231', '#911EB4', '#46F0F0', '#D2F53C', '#FABEBE', '#008080', '#E6BEFF', '#AA6E28', '#800000', '#000075', '#808000', '#000000', '#9A6324', '#469990', '#FFFAC8', '#DCBEFF'];
    private const array TEXT_COLORS = ['#FFFFFF', '#FFFFFF', '#000000', '#000000', '#000000', '#FFFFFF', '#000000', '#000000', '#000000', '#FFFFFF', '#000000', '#000000', '#FFFFFF', '#FFFFFF', '#000000', '#FFFFFF', '#FFFFFF', '#000000', '#000000', '#000000'];

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
SELECT id, name, badge_background_color, badge_text_color
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

        foreach ($data as $i => $row) {
            /**
             * @var array{
             *     id: string,
             *     name: string,
             *     badge_background_color: null|string,
             *     badge_text_color: null|string,
             * } $row
             */

            $results[$row['id']] = new CompetitionRoundInfo(
                id: $row['id'],
                name: $row['name'],
                textColor: $row['badge_text_color'] ?? self::TEXT_COLORS[$i],
                color: $row['badge_background_color'] ?? self::COLORS[$i],
            );
        }

        return $results;
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
