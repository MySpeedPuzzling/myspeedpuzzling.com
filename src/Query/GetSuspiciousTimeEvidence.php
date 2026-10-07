<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Value\PuzzleSecrecy;
use SpeedPuzzling\Web\Value\SuspicionEvidence;
use SpeedPuzzling\Web\Value\SuspicionEvidenceRequest;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;

/**
 * The facts behind the explanations of raised times (docs/features/suspicious-time-review.md, "Explanations") - asked
 * only for the times the classifier raised, a few hundred at most:
 *
 * - the comment and a "Yes, it's right" confirmation from the form (stored times only);
 * - how many other solo results the player has (new player);
 * - how many other non-suspicious solo results the puzzle has and the fastest of them (fastest on the puzzle);
 * - other players' pair/team results of the puzzle on the same solved day (teammates saved a group);
 * - the person's own pair/team results - tracked or as a registered member (often in a group);
 * - puzzles of the same brand with another piece count whose names are alike: the best trigram similarity of any
 *   name of one (main title and other names, unaccented, lower case) against any name of the other, the matching of
 *   DuplicatePuzzleSignalScoring (another edition). Never a puzzle a competition keeps secret (PuzzleSecrecy) or
 *   one hidden (hide_until): its name would end up in a reason a player or a moderator reads. The add/edit form
 *   leaves this lookup out (withOtherEditions: false) - it costs 20-40 ms, the rest of the facts ~1 ms.
 *
 * The classifier decides what they mean - only the edition similarity is cut here, at its threshold.
 */
readonly final class GetSuspiciousTimeEvidence
{
    // The requests as a relation: one statement answers all of them
    private const string REQUESTS = <<<SQL
WITH request AS (
    SELECT *
    FROM unnest(
        CAST(:keys AS text[]),
        CAST(:timeIds AS uuid[]),
        CAST(:playerIds AS uuid[]),
        CAST(:puzzleIds AS uuid[]),
        CAST(:days AS date[])
    ) AS request(key, time_id, player_id, puzzle_id, solved_day)
)
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * True for a puzzle whose name a reason may carry: neither kept secret by a competition (also while only its
     * picture is hidden) nor hidden (hide_until - placeholders like the Ravensburger Puzzle Month). Bind :now as
     * 'Y-m-d H:i:s'.
     */
    public static function sqlMayBeNamed(string $puzzleAlias, string $nowParameter = ':now'): string
    {
        return PuzzleSecrecy::sqlNotSecret($puzzleAlias, $nowParameter)
            . " AND ({$puzzleAlias}.hide_until IS NULL OR {$puzzleAlias}.hide_until <= {$nowParameter}::timestamp)";
    }

    /**
     * Reasons fit to show now: an "another edition" whose puzzle became secret or hidden after the reason was found is
     * left out (the scan never names one, but a puzzle can be hidden later). One statement, and only when such a reason
     * is there at all.
     *
     * @param array<string, list<SuspiciousTimeReason>> $reasonLists any keys
     * @return array<string, list<SuspiciousTimeReason>> the same keys
     */
    public function withoutUnnameable(array $reasonLists): array
    {
        $editionIds = [];

        foreach ($reasonLists as $reasons) {
            foreach ($reasons as $reason) {
                $editionId = self::editionIdOf($reason);

                if ($editionId !== null) {
                    $editionIds[$editionId] = true;
                }
            }
        }

        if ($editionIds === []) {
            return $reasonLists;
        }

        $mayBeNamed = self::sqlMayBeNamed('puzzle');

        /** @var list<string> $unnameableIds */
        $unnameableIds = $this->database->fetchFirstColumn(
            "SELECT puzzle.id FROM puzzle WHERE puzzle.id IN (:ids) AND NOT ({$mayBeNamed})",
            ['ids' => array_keys($editionIds), 'now' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['ids' => ArrayParameterType::STRING],
        );
        $unnameable = array_fill_keys(array_map('strtolower', $unnameableIds), true);

        return array_map(
            static fn (array $reasons): array => array_values(array_filter(
                $reasons,
                static fn (SuspiciousTimeReason $reason): bool => isset($unnameable[self::editionIdOf($reason) ?? '']) === false,
            )),
            $reasonLists,
        );
    }

    /**
     * The puzzle an "another edition" reason names, lower case - null for every other reason.
     */
    private static function editionIdOf(SuspiciousTimeReason $reason): null|string
    {
        $puzzleId = $reason->param('puzzle_id');

        return $reason->code === SuspiciousTimeReasonCode::OtherEdition && is_string($puzzleId) && Uuid::isValid($puzzleId)
            ? strtolower($puzzleId)
            : null;
    }

    /**
     * @param list<SuspicionEvidenceRequest> $requests
     * @param bool $withOtherEditions false = no "another edition" facts (the add/edit form)
     * @return array<string, SuspicionEvidence> keyed by request key
     */
    public function forTimes(array $requests, bool $withOtherEditions = true): array
    {
        if ($requests === []) {
            return [];
        }

        $parameters = [
            'keys' => self::arrayLiteral(array_map(static fn (SuspicionEvidenceRequest $request): string => $request->key, $requests)),
            'timeIds' => self::arrayLiteral(array_map(static fn (SuspicionEvidenceRequest $request): string => $request->timeId ?? 'NULL', $requests)),
            'playerIds' => self::arrayLiteral(array_map(static fn (SuspicionEvidenceRequest $request): string => $request->playerId, $requests)),
            'puzzleIds' => self::arrayLiteral(array_map(static fn (SuspicionEvidenceRequest $request): string => $request->puzzleId, $requests)),
            'days' => self::arrayLiteral(array_map(static fn (SuspicionEvidenceRequest $request): string => $request->solvedDay, $requests)),
        ];

        $facts = $this->facts($parameters);
        $sameDayGroupResults = $this->sameDayGroupResults($parameters);
        $groupResults = $this->groupResults($parameters);
        $otherEditions = $withOtherEditions ? $this->otherEditions(array_values(array_unique(array_map(
            static fn (SuspicionEvidenceRequest $request): string => $request->puzzleId,
            $requests,
        )))) : [];

        $evidence = [];

        foreach ($requests as $request) {
            $fact = $facts[$request->key] ?? null;

            $evidence[$request->key] = new SuspicionEvidence(
                comment: $fact['comment'] ?? null,
                otherSoloResults: $fact['other_solo_results'] ?? 0,
                sameDayGroupResults: $sameDayGroupResults[$request->key] ?? [],
                groupResults: $groupResults[$request->playerId] ?? [],
                otherEditions: $otherEditions[$request->puzzleId] ?? [],
                otherResultsOnPuzzle: $fact['other_results_on_puzzle'] ?? 0,
                fastestOtherSeconds: $fact['fastest_other_seconds'] ?? null,
                confirmedExpectedSeconds: $fact['confirmed_expected_seconds'] ?? null,
            );
        }

        return $evidence;
    }

    /**
     * @param array<string, string> $parameters
     * @return array<string, array{comment: null|string, other_solo_results: int, other_results_on_puzzle: int, fastest_other_seconds: null|int, confirmed_expected_seconds: null|int}>
     */
    private function facts(array $parameters): array
    {
        $query = self::REQUESTS . "\n" . <<<SQL
SELECT
    request.key,
    (SELECT pst.comment FROM puzzle_solving_time pst WHERE pst.id = request.time_id) AS comment,
    (
        SELECT COUNT(*)
        FROM puzzle_solving_time own
        WHERE own.player_id = request.player_id
            AND own.puzzling_type = 'solo'
            AND own.seconds_to_solve > 0
            AND own.suspicious = false
            AND own.id IS DISTINCT FROM request.time_id
    ) AS other_solo_results,
    on_puzzle.results AS other_results_on_puzzle,
    on_puzzle.fastest AS fastest_other_seconds,
    (
        SELECT confirmation.expected_seconds
        FROM suspicious_time_confirmation confirmation
        WHERE confirmation.time_id = request.time_id
        ORDER BY confirmation.confirmed_at DESC
        LIMIT 1
    ) AS confirmed_expected_seconds
FROM request
CROSS JOIN LATERAL (
    SELECT COUNT(*) AS results, MIN(other.seconds_to_solve) AS fastest
    FROM puzzle_solving_time other
    WHERE other.puzzle_id = request.puzzle_id
        AND other.puzzling_type = 'solo'
        AND other.seconds_to_solve > 0
        AND other.suspicious = false
        AND other.id IS DISTINCT FROM request.time_id
) on_puzzle
SQL;

        /** @var list<array{key: string, comment: null|string, other_solo_results: int|string, other_results_on_puzzle: int|string, fastest_other_seconds: null|int|string, confirmed_expected_seconds: null|int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $parameters);

        $facts = [];

        foreach ($rows as $row) {
            $facts[$row['key']] = [
                'comment' => $row['comment'],
                'other_solo_results' => (int) $row['other_solo_results'],
                'other_results_on_puzzle' => (int) $row['other_results_on_puzzle'],
                'fastest_other_seconds' => $row['fastest_other_seconds'] === null ? null : (int) $row['fastest_other_seconds'],
                'confirmed_expected_seconds' => $row['confirmed_expected_seconds'] === null ? null : (int) $row['confirmed_expected_seconds'],
            ];
        }

        return $facts;
    }

    /**
     * Pair/team results of the puzzle that somebody else saved on the same solved day - suspicious or not, it is a
     * hint that the time was a group's.
     *
     * Judged by what the player may see (docs/features/player-blocklist.md): the explanation ends up in front of them -
     * the form's notice, the review page, the e-mail - so a result of somebody they blocked (its tracker or a member)
     * never counts, unless they took part in it themselves. The scan asks the same, so a reason never rests on such a
     * result. A private player's result counts: it names nobody, and puzzle boards list it as a hidden puzzler's anyway.
     *
     * @param array<string, string> $parameters
     * @return array<string, list<array{time_id: string, seconds: int, puzzling_type: string}>> keyed by request key
     */
    private function sameDayGroupResults(array $parameters): array
    {
        $query = self::REQUESTS . "\n" . <<<SQL
SELECT request.key, other.id AS time_id, other.seconds_to_solve, other.puzzling_type
FROM request
INNER JOIN puzzle_solving_time other
    ON other.puzzle_id = request.puzzle_id
    AND other.player_id <> request.player_id
    AND other.puzzling_type <> 'solo'
    AND other.seconds_to_solve > 0
    AND COALESCE(other.finished_at, other.tracked_at)::date = request.solved_day
WHERE NOT EXISTS (
        SELECT 1
        FROM user_block block
        WHERE block.blocker_id = request.player_id
            AND (
                block.blocked_id = other.player_id
                OR EXISTS (SELECT 1 FROM puzzling_team_member blocked_member WHERE blocked_member.team_id = other.puzzling_team_id AND blocked_member.player_id = block.blocked_id)
            )
    )
    OR EXISTS (SELECT 1 FROM puzzling_team_member own WHERE own.team_id = other.puzzling_team_id AND own.player_id = request.player_id)
ORDER BY request.key, other.id
SQL;

        /** @var list<array{key: string, time_id: string, seconds_to_solve: int|string, puzzling_type: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $parameters);

        $results = [];

        foreach ($rows as $row) {
            $results[$row['key']][] = [
                'time_id' => $row['time_id'],
                'seconds' => (int) $row['seconds_to_solve'],
                'puzzling_type' => $row['puzzling_type'],
            ];
        }

        return $results;
    }

    /**
     * The person's pair/team results: every result of a puzzling team they are a registered member of (the tracker
     * is always one). Not suspicious, with a time.
     *
     * @param array<string, string> $parameters
     * @return array<string, list<array{pieces: int, seconds: int}>> keyed by player id
     */
    private function groupResults(array $parameters): array
    {
        $query = self::REQUESTS . "\n" . <<<SQL
SELECT person.player_id, p.pieces_count, pst.seconds_to_solve
FROM (SELECT DISTINCT player_id FROM request) person
INNER JOIN puzzling_team_member member ON member.player_id = person.player_id
INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = member.team_id
INNER JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.seconds_to_solve > 0
    AND pst.suspicious = false
    AND p.pieces_count > 0
SQL;

        /** @var list<array{player_id: string, pieces_count: int|string, seconds_to_solve: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $parameters);

        $results = [];

        foreach ($rows as $row) {
            $results[$row['player_id']][] = [
                'pieces' => (int) $row['pieces_count'],
                'seconds' => (int) $row['seconds_to_solve'],
            ];
        }

        return $results;
    }

    /**
     * Puzzles of the same brand with another piece count and a similar name, never a secret or hidden one
     * (sqlMayBeNamed()). The plain main-title similarity is cheap and narrows a brand of thousands of puzzles down
     * first; every name is compared only for those pairs (or where either puzzle has other names at all).
     *
     * @param list<string> $puzzleIds
     * @return array<string, list<array{puzzle_id: string, name: string, pieces: int, similarity: float}>> keyed by puzzle id
     */
    private function otherEditions(array $puzzleIds): array
    {
        $mayBeNamed = self::sqlMayBeNamed('other');

        $query = <<<SQL
WITH pair AS MATERIALIZED (
    SELECT
        this.id AS puzzle_id,
        other.id AS other_id,
        other.name AS other_name,
        other.pieces_count AS other_pieces,
        ARRAY[lower(immutable_unaccent(this.name))] || ARRAY(SELECT lower(immutable_unaccent(alternative ->> 'name')) FROM jsonb_array_elements(this.alternative_names) AS alternative) AS this_names,
        ARRAY[lower(immutable_unaccent(other.name))] || ARRAY(SELECT lower(immutable_unaccent(alternative ->> 'name')) FROM jsonb_array_elements(other.alternative_names) AS alternative) AS other_names
    FROM puzzle this
    INNER JOIN puzzle other
        ON other.manufacturer_id = this.manufacturer_id
        AND other.pieces_count <> this.pieces_count
        AND other.pieces_count > 0
        AND {$mayBeNamed}
    WHERE this.id = ANY(CAST(:puzzleIds AS uuid[]))
        AND (
            jsonb_array_length(this.alternative_names) > 0
            OR jsonb_array_length(other.alternative_names) > 0
            OR similarity(lower(immutable_unaccent(this.name)), lower(immutable_unaccent(other.name))) >= :minSimilarity
        )
)
SELECT pair.puzzle_id, pair.other_id, pair.other_name, pair.other_pieces, names.similarity
FROM pair
CROSS JOIN LATERAL (
    SELECT COALESCE(MAX(similarity(name_a, name_b)), 0) AS similarity
    FROM unnest(pair.this_names) AS name_a
    CROSS JOIN unnest(pair.other_names) AS name_b
    WHERE name_a <> '' AND name_b <> ''
) names
WHERE names.similarity >= :minSimilarity
ORDER BY pair.puzzle_id, names.similarity DESC, pair.other_id
SQL;

        /** @var list<array{puzzle_id: string, other_id: string, other_name: string, other_pieces: int|string, similarity: float|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'puzzleIds' => self::arrayLiteral($puzzleIds),
            'minSimilarity' => SuspiciousTimeClassifier::OTHER_EDITION_MIN_SIMILARITY,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $editions = [];

        foreach ($rows as $row) {
            $editions[$row['puzzle_id']][] = [
                'puzzle_id' => $row['other_id'],
                'name' => $row['other_name'],
                'pieces' => (int) $row['other_pieces'],
                'similarity' => (float) $row['similarity'],
            ];
        }

        return $editions;
    }

    /**
     * Keys, uuids and dates only - nothing that needs quoting inside a Postgres array literal.
     *
     * @param array<string> $values
     */
    private static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }
}
