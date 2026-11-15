<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\UnlistedPuzzleNotFound;

/**
 * THE reader of a player's unlisted puzzles (docs/features/data-import/unlisted-puzzles.md) - for their own pages
 * only. **Every statement is bound to the owner** (`WHERE player_id = :ownerPlayerId`), and the callers pass the
 * signed-in player's id, never one from an address: nobody else's unlisted puzzle can be read through here, and no
 * other query reads these tables (UnlistedDataCoverageTest).
 */
readonly final class GetUnlistedPuzzles
{
    /**
     * On the list: an unlisted puzzle that is not paired, or a paired one something unlisted is still left on.
     */
    private const string SQL_LISTED = <<<SQL
(
    unlisted_puzzle.paired_at IS NULL
    OR EXISTS (SELECT 1 FROM unlisted_result left_result WHERE left_result.unlisted_puzzle_id = unlisted_puzzle.id AND left_result.converted_at IS NULL)
    OR EXISTS (SELECT 1 FROM unlisted_ownership left_mark WHERE left_mark.unlisted_puzzle_id = unlisted_puzzle.id AND left_mark.converted_at IS NULL)
)
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * A page of the player's list, the suggested ones first, then by name. One statement.
     *
     * @return list<array{id: string, name: string, brandText: null|string, piecesCount: null|int, results: int, owned: bool, wishList: bool, suggested: bool, paired: bool}>
     */
    public function page(string $ownerPlayerId, int $limit, int $offset): array
    {
        if (Uuid::isValid($ownerPlayerId) === false) {
            return [];
        }

        $listed = self::SQL_LISTED;

        /** @var list<array{id: string, name: string, brand_text: null|string, pieces_count: null|int, results: int, owned: bool, wish_list: bool, suggested: bool, paired: bool}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT
    unlisted_puzzle.id,
    unlisted_puzzle.name,
    unlisted_puzzle.brand_text,
    unlisted_puzzle.pieces_count,
    (SELECT COUNT(*) FROM unlisted_result result WHERE result.unlisted_puzzle_id = unlisted_puzzle.id AND result.converted_at IS NULL) AS results,
    EXISTS (SELECT 1 FROM unlisted_ownership mark WHERE mark.unlisted_puzzle_id = unlisted_puzzle.id AND mark.kind = 'owned' AND mark.converted_at IS NULL) AS owned,
    EXISTS (SELECT 1 FROM unlisted_ownership mark WHERE mark.unlisted_puzzle_id = unlisted_puzzle.id AND mark.kind = 'wish_list' AND mark.converted_at IS NULL) AS wish_list,
    unlisted_puzzle.suggested_puzzle_id IS NOT NULL AND unlisted_puzzle.paired_at IS NULL AS suggested,
    unlisted_puzzle.paired_at IS NOT NULL AS paired
FROM unlisted_puzzle
WHERE unlisted_puzzle.player_id = :ownerPlayerId
    AND {$listed}
ORDER BY (unlisted_puzzle.suggested_puzzle_id IS NOT NULL AND unlisted_puzzle.paired_at IS NULL) DESC, LOWER(unlisted_puzzle.name), unlisted_puzzle.id
LIMIT :limit OFFSET :offset
SQL, ['ownerPlayerId' => $ownerPlayerId, 'limit' => max(1, $limit), 'offset' => max(0, $offset)])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'brandText' => $row['brand_text'],
            'piecesCount' => $row['pieces_count'],
            'results' => $row['results'],
            'owned' => $row['owned'],
            'wishList' => $row['wish_list'],
            'suggested' => $row['suggested'],
            'paired' => $row['paired'],
        ], $rows);
    }

    /**
     * The numbers above the list. One statement.
     *
     * @return array{puzzles: int, results: int, suggested: int}
     */
    public function summary(string $ownerPlayerId): array
    {
        if (Uuid::isValid($ownerPlayerId) === false) {
            return ['puzzles' => 0, 'results' => 0, 'suggested' => 0];
        }

        $listed = self::SQL_LISTED;

        /** @var false|array{puzzles: int, results: int, suggested: int} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    COUNT(*) AS puzzles,
    COALESCE(SUM((SELECT COUNT(*) FROM unlisted_result result WHERE result.unlisted_puzzle_id = unlisted_puzzle.id AND result.converted_at IS NULL)), 0)::int AS results,
    COUNT(*) FILTER (WHERE unlisted_puzzle.suggested_puzzle_id IS NOT NULL AND unlisted_puzzle.paired_at IS NULL) AS suggested
FROM unlisted_puzzle
WHERE unlisted_puzzle.player_id = :ownerPlayerId
    AND {$listed}
SQL, ['ownerPlayerId' => $ownerPlayerId])->fetchAssociative();

        return $row === false ? ['puzzles' => 0, 'results' => 0, 'suggested' => 0] : $row;
    }

    /**
     * One unlisted puzzle of the player with everything on it: what the file said, its results and marks - unlisted,
     * or what they became -, the file it came from, its pairings. Four statements.
     *
     * @return array{
     *     id: string, name: string, brandText: null|string, piecesCount: null|int, eans: list<string>, brandCodes: list<string>,
     *     pictureUrl: null|string, createdAt: DateTimeImmutable, fileName: null|string, importId: null|string,
     *     pairedPuzzleId: null|string, pairedAt: null|DateTimeImmutable, suggestedPuzzleId: null|string, unlistedLeft: int,
     *     results: list<array{id: string, seconds: null|int, solvedOn: null|string, datePrecision: null|string, type: string, people: list<string>, firstAttempt: null|bool, comment: null|string, unlisted: bool, solvingTimeId: null|string, keptReason: null|string}>,
     *     marks: list<array{id: string, kind: string, comment: null|string, collectionLabel: null|string, unlisted: bool, keptReason: null|string}>,
     *     pairings: list<array{id: string, puzzleId: string, state: string, startedAt: DateTimeImmutable, finishedAt: null|DateTimeImmutable, undoneAt: null|DateTimeImmutable, resultsAdded: int, resultsAlreadyYours: int, resultsKept: int, itemsAdded: int, itemsAlreadyYours: int, resultsTakenBack: int, resultsKeptByUndo: int}>
     * }
     *
     * @throws UnlistedPuzzleNotFound
     */
    public function one(string $ownerPlayerId, string $unlistedPuzzleId): array
    {
        if (Uuid::isValid($ownerPlayerId) === false || Uuid::isValid($unlistedPuzzleId) === false) {
            throw new UnlistedPuzzleNotFound();
        }

        $parameters = ['ownerPlayerId' => $ownerPlayerId, 'unlistedPuzzleId' => $unlistedPuzzleId];

        /** @var false|array{id: string, name: string, brand_text: null|string, pieces_count: null|int, eans: string, brand_codes: string, picture_url: null|string, created_at: string, file_name: null|string, import_id: null|string, paired_puzzle_id: null|string, paired_at: null|string, suggested_puzzle_id: null|string} $puzzle */
        $puzzle = $this->database->executeQuery(<<<SQL
SELECT
    unlisted_puzzle.id,
    unlisted_puzzle.name,
    unlisted_puzzle.brand_text,
    unlisted_puzzle.pieces_count,
    unlisted_puzzle.eans,
    unlisted_puzzle.brand_codes,
    unlisted_puzzle.picture_url,
    unlisted_puzzle.created_at,
    -- The file it came from - the player's own import only
    data_import.file_name,
    data_import.id AS import_id,
    unlisted_puzzle.paired_puzzle_id,
    unlisted_puzzle.paired_at,
    CASE WHEN unlisted_puzzle.paired_at IS NULL THEN unlisted_puzzle.suggested_puzzle_id END AS suggested_puzzle_id
FROM unlisted_puzzle
LEFT JOIN data_import ON data_import.id = unlisted_puzzle.created_by_import_id
    AND data_import.player_id = unlisted_puzzle.player_id
    AND data_import.status <> 'discarded'
WHERE unlisted_puzzle.id = :unlistedPuzzleId
    AND unlisted_puzzle.player_id = :ownerPlayerId
SQL, $parameters)->fetchAssociative();

        if ($puzzle === false) {
            throw new UnlistedPuzzleNotFound();
        }

        /** @var list<array{id: string, seconds_to_solve: null|int, solved_on: null|string, date_precision: null|string, puzzling_type: string, people: string, first_attempt: null|bool, comment: null|string, converted_at: null|string, converted_solving_time_id: null|string, kept_reason: null|string}> $results */
        $results = $this->database->executeQuery(<<<SQL
SELECT
    result.id,
    result.seconds_to_solve,
    result.solved_on,
    result.date_precision,
    result.puzzling_type,
    result.people,
    result.first_attempt,
    result.comment,
    result.converted_at,
    result.converted_solving_time_id,
    result.kept_reason
FROM unlisted_result result
WHERE result.unlisted_puzzle_id = :unlistedPuzzleId
    AND result.player_id = :ownerPlayerId
ORDER BY result.solved_on NULLS LAST, result.row_number, result.id
SQL, $parameters)->fetchAllAssociative();

        /** @var list<array{id: string, kind: string, comment: null|string, collection_label: null|string, converted_at: null|string, kept_reason: null|string}> $marks */
        $marks = $this->database->executeQuery(<<<SQL
SELECT mark.id, mark.kind, mark.comment, mark.collection_label, mark.converted_at, mark.kept_reason
FROM unlisted_ownership mark
WHERE mark.unlisted_puzzle_id = :unlistedPuzzleId
    AND mark.player_id = :ownerPlayerId
ORDER BY mark.kind, mark.id
SQL, $parameters)->fetchAllAssociative();

        /** @var list<array{id: string, puzzle_id: string, state: string, started_at: string, finished_at: null|string, undone_at: null|string, results_added: int, results_already_yours: int, results_kept: int, items_added: int, items_already_yours: int, results_taken_back: int, results_kept_by_undo: int}> $pairings */
        $pairings = $this->database->executeQuery(<<<SQL
SELECT
    pairing.id, pairing.puzzle_id, pairing.state, pairing.started_at, pairing.finished_at, pairing.undone_at,
    pairing.results_added, pairing.results_already_yours, pairing.results_kept, pairing.items_added,
    pairing.items_already_yours, pairing.results_taken_back, pairing.results_kept_by_undo
FROM unlisted_pairing pairing
WHERE pairing.unlisted_puzzle_id = :unlistedPuzzleId
    AND pairing.player_id = :ownerPlayerId
ORDER BY pairing.started_at DESC, pairing.id DESC
LIMIT 5
SQL, $parameters)->fetchAllAssociative();

        $unlistedLeft = 0;

        foreach ([...$results, ...$marks] as $subject) {
            $unlistedLeft += $subject['converted_at'] === null ? 1 : 0;
        }

        return [
            'id' => $puzzle['id'],
            'name' => $puzzle['name'],
            'brandText' => $puzzle['brand_text'],
            'piecesCount' => $puzzle['pieces_count'],
            'eans' => self::strings($puzzle['eans']),
            'brandCodes' => self::strings($puzzle['brand_codes']),
            'pictureUrl' => $puzzle['picture_url'],
            'createdAt' => new DateTimeImmutable($puzzle['created_at']),
            'fileName' => $puzzle['file_name'],
            'importId' => $puzzle['import_id'],
            'pairedPuzzleId' => $puzzle['paired_puzzle_id'],
            'pairedAt' => $puzzle['paired_at'] === null ? null : new DateTimeImmutable($puzzle['paired_at']),
            'suggestedPuzzleId' => $puzzle['suggested_puzzle_id'],
            'unlistedLeft' => $unlistedLeft,
            'results' => array_map(static fn (array $result): array => [
                'id' => $result['id'],
                'seconds' => $result['seconds_to_solve'],
                'solvedOn' => $result['solved_on'],
                'datePrecision' => $result['date_precision'],
                'type' => $result['puzzling_type'],
                'people' => self::strings($result['people']),
                'firstAttempt' => $result['first_attempt'],
                'comment' => $result['comment'],
                'unlisted' => $result['converted_at'] === null,
                'solvingTimeId' => $result['converted_at'] === null ? null : $result['converted_solving_time_id'],
                'keptReason' => $result['kept_reason'],
            ], $results),
            'marks' => array_map(static fn (array $mark): array => [
                'id' => $mark['id'],
                'kind' => $mark['kind'],
                'comment' => $mark['comment'],
                'collectionLabel' => $mark['collection_label'],
                'unlisted' => $mark['converted_at'] === null,
                'keptReason' => $mark['kept_reason'],
            ], $marks),
            'pairings' => array_map(static fn (array $pairing): array => [
                'id' => $pairing['id'],
                'puzzleId' => $pairing['puzzle_id'],
                'state' => $pairing['state'],
                'startedAt' => new DateTimeImmutable($pairing['started_at']),
                'finishedAt' => $pairing['finished_at'] === null ? null : new DateTimeImmutable($pairing['finished_at']),
                'undoneAt' => $pairing['undone_at'] === null ? null : new DateTimeImmutable($pairing['undone_at']),
                'resultsAdded' => $pairing['results_added'],
                'resultsAlreadyYours' => $pairing['results_already_yours'],
                'resultsKept' => $pairing['results_kept'],
                'itemsAdded' => $pairing['items_added'],
                'itemsAlreadyYours' => $pairing['items_already_yours'],
                'resultsTakenBack' => $pairing['results_taken_back'],
                'resultsKeptByUndo' => $pairing['results_kept_by_undo'],
            ], $pairings),
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }
}
