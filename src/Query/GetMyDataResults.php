<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;
use SpeedPuzzling\Web\Results\MyData\MyDataPage;
use SpeedPuzzling\Web\Results\MyData\MyDataResultRow;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;
use SpeedPuzzling\Web\Value\MyDataCriteria;
use SpeedPuzzling\Web\Value\MyDataSort;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * The signed-in player's own results as a table (docs/features/my-data.md): every result they tracked or took part
 * in - solo results by player, pair/team results through the registered membership (the index-friendly form of
 * GetExportablePuzzleSolveSummary; the tracker is a member of their team, so nothing comes twice). Own data, like the
 * export: the caller passes the id of the player who is signed in, never an id from a URL.
 *
 * - A suspicious ("needs verification") result is the player's own and is listed, with its label - nothing is ranked
 *   or counted here.
 * - A result without a tracker (an organiser's sheet, nobody's yet) has no member, so it is never anybody's row; a
 *   claimed one is read through the membership like every pair/team result.
 * - No event is shown or joined: a series pick needs nothing here.
 * - The date is `finished_at` with its precision, `tracked_at` (always a day) for a result without one.
 *
 * One statement per page whatever the number of results; filtered, sorted and paged in SQL.
 */
readonly final class GetMyDataResults
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    private const string MINE = <<<SQL
mine AS (
    SELECT pst.id
    FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId
        AND pst.puzzling_team_id IS NULL
    UNION
    SELECT pst.id
    FROM puzzling_team_member member
    INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = member.team_id
    WHERE member.player_id = :playerId
)
SQL;

    /**
     * How many results the player has at all and the year of the oldest one - for the heading and the year filter.
     *
     * @return array{total: int, oldestYear: null|int}
     */
    public function facts(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return ['total' => 0, 'oldestYear' => null];
        }

        $mine = self::MINE;

        $query = <<<SQL
WITH {$mine}
SELECT COUNT(*) AS total, MIN(COALESCE(pst.finished_at, pst.tracked_at)) AS oldest
FROM mine
INNER JOIN puzzle_solving_time pst ON pst.id = mine.id
SQL;

        /** @var false|array{total: int|string, oldest: null|string} $row */
        $row = $this->database->executeQuery($query, ['playerId' => $playerId])->fetchAssociative();

        if ($row === false) {
            return ['total' => 0, 'oldestYear' => null];
        }

        return [
            'total' => (int) $row['total'],
            'oldestYear' => $row['oldest'] !== null ? (int) new DateTimeImmutable($row['oldest'])->format('Y') : null,
        ];
    }

    /**
     * @return MyDataPage<MyDataResultRow>
     */
    public function page(string $playerId, MyDataCriteria $criteria): MyDataPage
    {
        if (Uuid::isValid($playerId) === false) {
            return new MyDataPage([], 0, 1);
        }

        $page = $this->fetch($playerId, $criteria);

        // A page behind the last row (an old link, a row deleted meanwhile): the first page instead of an empty one
        if ($page->rows === [] && $criteria->page > 1) {
            $page = $this->fetch($playerId, $criteria->onPage(1));
        }

        return $page;
    }

    /**
     * @return MyDataPage<MyDataResultRow>
     */
    private function fetch(string $playerId, MyDataCriteria $criteria): MyDataPage
    {
        $now = $this->clock->now();
        $conditions = [];
        $parameters = [
            'playerId' => $playerId,
            'now' => $now->format('Y-m-d H:i:s'),
        ];

        $search = PuzzleTextSearch::fromUserInput($criteria->search);

        if ($criteria->search !== null && $search->isEmpty() === false) {
            // The catalogue's own search rule for the puzzle's names and codes, or the brand's name holds the text
            $conditions[] = '(' . $search->condition('p') . ' OR m.name ILIKE :brandContains)';
            $parameters = [...$parameters, ...$search->parameters($now)];
            $parameters['brandContains'] = '%' . addcslashes($criteria->search, '%_\\') . '%';
        }

        if ($criteria->pieces?->minPieces !== null) {
            $conditions[] = 'p.pieces_count >= :minPieces';
            $parameters['minPieces'] = $criteria->pieces->minPieces;
        }

        if ($criteria->pieces?->maxPieces !== null) {
            $conditions[] = 'p.pieces_count <= :maxPieces';
            $parameters['maxPieces'] = $criteria->pieces->maxPieces;
        }

        if ($criteria->solvingType !== null) {
            $conditions[] = 'pst.puzzling_type = :solvingType';
            $parameters['solvingType'] = $criteria->solvingType;
        }

        if ($criteria->year !== null) {
            $conditions[] = 'COALESCE(pst.finished_at, pst.tracked_at) >= :yearStart AND COALESCE(pst.finished_at, pst.tracked_at) < :yearEnd';
            $parameters['yearStart'] = sprintf('%04d-01-01 00:00:00', $criteria->year);
            $parameters['yearEnd'] = sprintf('%04d-01-01 00:00:00', $criteria->year + 1);
        }

        if ($criteria->firstTryOnly) {
            $conditions[] = 'pst.first_attempt = true';
        }

        if ($criteria->importedOnly) {
            $conditions[] = 'pst.created_via = :importedVia';
            $parameters['importedVia'] = SolvingTimeSource::SpreadsheetImport->value;
        }

        if ($criteria->withComment) {
            $conditions[] = "pst.comment IS NOT NULL AND btrim(pst.comment) <> ''";
        }

        if ($criteria->withPhoto) {
            $conditions[] = 'pst.finished_puzzle_photo IS NOT NULL';
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode("\n    AND ", $conditions);
        $direction = $criteria->descending ? 'DESC' : 'ASC';

        // Every order ends on the result's id: equal values never straddle two pages
        $orderBy = match ($criteria->sort) {
            MyDataSort::Time => "pst.seconds_to_solve {$direction} NULLS LAST, pst.id",
            MyDataSort::Pieces => "p.pieces_count {$direction}, p.name, pst.id",
            MyDataSort::Name => "p.name {$direction}, pst.id",
            MyDataSort::Brand => "m.name {$direction} NULLS LAST, p.name, pst.id",
            default => "COALESCE(pst.finished_at, pst.tracked_at) {$direction}, pst.tracked_at {$direction}, pst.id",
        };

        $mine = self::MINE;
        $puzzleColumns = ExportablePuzzle::SQL_COLUMNS;
        $limit = MyDataCriteria::PAGE_SIZE;
        $offset = $criteria->offset();

        $query = <<<SQL
WITH {$mine}
SELECT
    pst.id AS time_id,
    pst.seconds_to_solve,
    pst.finished_at,
    pst.finished_at_precision,
    pst.tracked_at,
    pst.first_attempt,
    pst.finished_puzzle_photo IS NOT NULL AS has_photo,
    pst.comment,
    pst.puzzling_type,
    pst.puzzlers_count,
    pst.created_via,
    pst.suspicious,
    (
        SELECT string_agg(
            COALESCE(
                other.name,
                player_elem.player ->> 'player_name',
                CASE WHEN other.code IS NOT NULL THEN '#' || UPPER(other.code) ELSE NULL END
            ),
            ', '
            ORDER BY player_elem.ordinality
        )
        FROM json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
        LEFT JOIN player other ON other.id = (player_elem.player ->> 'player_id')::UUID
        WHERE (player_elem.player ->> 'player_id') IS DISTINCT FROM :playerId
            AND COALESCE(other.name, player_elem.player ->> 'player_name', other.code) IS NOT NULL
    ) AS with_whom,
    (SELECT puzzling_team.name FROM puzzling_team WHERE puzzling_team.id = pst.puzzling_team_id) AS team_name,
    {$puzzleColumns},
    COUNT(*) OVER () AS total
FROM mine
INNER JOIN puzzle_solving_time pst ON pst.id = mine.id
INNER JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
{$where}
ORDER BY {$orderBy}
LIMIT {$limit} OFFSET {$offset}
SQL;

        $rows = $this->database->executeQuery($query, $parameters)->fetchAllAssociative();

        $results = [];
        $total = 0;

        foreach ($rows as $row) {
            $puzzle = ExportablePuzzle::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl);

            if ($puzzle === null) {
                continue;
            }

            /** @var int|string $rowTotal */
            $rowTotal = $row['total'];
            $total = (int) $rowTotal;
            $results[] = MyDataResultRow::fromDatabaseRow($row, $puzzle);
        }

        return new MyDataPage($results, $total, $criteria->page);
    }
}
