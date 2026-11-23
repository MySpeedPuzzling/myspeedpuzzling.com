<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\RoundPuzzleLabel;

/**
 * Name plates with time boxes (table-labels-and-plates.md "Name plates"): what the tags page needs to offer and print a
 * box per challenge - the event's standings (a championship = a standing's rounds) and the rounds an advancement step
 * feeds (left out of a category's boxes: nobody has reached them yet), the round puzzles' labels (labels only, never a
 * puzzle's name - plates print before the reveal), and how many people sit at another table in a later round.
 */
readonly final class GetTagPlateChallenges
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * One statement: the standings (in their order) and the ids of the rounds an advancement step feeds.
     *
     * @return array{standings: list<array{id: string, name: string, category: string, roundIds: list<string>}>, fedRoundIds: list<string>}
     */
    public function options(string $competitionId): array
    {
        if (!Uuid::isValid($competitionId)) {
            return ['standings' => [], 'fedRoundIds' => []];
        }

        /** @var false|array{standings: null|string, fed: null|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    (
        SELECT json_agg(json_build_object('id', cs.id, 'name', cs.name, 'category', cs.category, 'roundIds', cs.round_ids) ORDER BY cs.position, cs.created_at, cs.id)
        FROM competition_standing cs
        WHERE cs.competition_id = :competitionId
    )::text AS standings,
    (
        SELECT json_agg(DISTINCT target.round_id)
        FROM competition_advancement_step cas
        CROSS JOIN LATERAL jsonb_array_elements_text(cas.target_round_ids) AS target(round_id)
        WHERE cas.competition_id = :competitionId
    )::text AS fed
SQL,
            ['competitionId' => $competitionId],
        );

        /** @var list<array{id: string, name: string, category: string, roundIds: list<string>}> $standings */
        $standings = $row === false || $row['standings'] === null ? [] : json_decode($row['standings'], true, flags: JSON_THROW_ON_ERROR);
        /** @var list<string> $fed */
        $fed = $row === false || $row['fed'] === null ? [] : json_decode($row['fed'], true, flags: JSON_THROW_ON_ERROR);

        return ['standings' => $standings, 'fedRoundIds' => array_map(strtolower(...), $fed)];
    }

    /**
     * The labels of each round's puzzles in the round's order (RoundPuzzleLabel - the organiser's label, else A, B, C).
     *
     * @param list<string> $roundIds
     * @return array<string, list<string>> round id => labels
     */
    public function puzzleLabels(array $roundIds): array
    {
        if ($roundIds === []) {
            return [];
        }

        $order = RoundPuzzleLabel::sqlOrder('crp');

        /** @var list<array{round_id: string, label: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT crp.round_id, crp.label
FROM competition_round_puzzle crp
WHERE crp.round_id IN (:roundIds)
ORDER BY crp.round_id, {$order}
SQL,
            ['roundIds' => $roundIds],
            ['roundIds' => ArrayParameterType::STRING],
        );

        $labels = [];

        foreach ($rows as $row) {
            $list = $labels[$row['round_id']] ?? [];
            $list[] = RoundPuzzleLabel::effective($row['label'], count($list) + 1);
            $labels[$row['round_id']] = $list;
        }

        return $labels;
    }

    /**
     * How many people of these rounds sit at more than one table among them (a person's table in a pair/team round is
     * their pair's/team's) - the plate prints one table for the whole championship.
     *
     * @param list<string> $roundIds
     */
    public function peopleWithSeveralTables(array $roundIds): int
    {
        if (count($roundIds) < 2) {
            return 0;
        }

        /** @var int|string $count */
        $count = $this->database->fetchOne(
            <<<SQL
SELECT COUNT(*) FROM (
    SELECT cpr.participant_id
    FROM competition_participant_round cpr
    INNER JOIN competition_round cr ON cr.id = cpr.round_id AND cr.table_numbers_off = false
    LEFT JOIN competition_team ct ON ct.id = cpr.team_id
    WHERE cpr.round_id IN (:roundIds)
    GROUP BY cpr.participant_id
    HAVING COUNT(DISTINCT CASE WHEN cr.category = 'solo' THEN cpr.table_number ELSE ct.table_number END) > 1
) several
SQL,
            ['roundIds' => $roundIds],
            ['roundIds' => ArrayParameterType::STRING],
        );

        return (int) $count;
    }
}
