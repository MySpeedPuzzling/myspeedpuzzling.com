<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Results\ImportCataloguePuzzle;
use SpeedPuzzling\Web\Results\ImportPuzzleCard;
use SpeedPuzzling\Web\Results\ImportPuzzlesOverview;
use SpeedPuzzling\Web\Results\ImportSheetRow;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;
use SpeedPuzzling\Web\Value\DataImportStatus;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * The pairing screens of the signed-in player's own import (docs/features/data-import/pairing-screens.md): the
 * summary, a page of a group's puzzles, one puzzle with its candidates, its rows, the next puzzle that waits, and
 * the search. **Every statement names the player** - the caller passes the id of who is signed in, never one from a
 * URL - and each screen costs a fixed number of statements whatever the import's size.
 *
 * A candidate is kept by its id at the time it was found: here it is read through `puzzle_redirect` (a puzzle
 * merged since is the puzzle it was merged into) and through GetImportPuzzleCandidates::sqlCandidate() (a puzzle
 * hidden since, or somebody else's unapproved one, is not offered). The search offers by the same rule.
 */
readonly final class GetImportPuzzleScreen
{
    /** The lists of the screen, by what the rules said - a card stays on its list when it is decided */
    public const array GROUPS = ['found', 'probably', 'choose', 'not-found', 'remembered'];
    public const int SEARCH_RESULTS = 20;
    public const int SEARCH_MIN_CHARACTERS = 2;

    private const array GROUP_CONDITION = [
        'found' => "import_puzzle.tier = 'found'",
        'probably' => "import_puzzle.tier = 'probably'",
        'choose' => "import_puzzle.tier = 'choose'",
        'not-found' => "import_puzzle.tier = 'none'",
        // Decided as in an earlier import of the player before the rules ever looked at it
        'remembered' => "import_puzzle.tier IS NULL AND import_puzzle.choice <> 'undecided'",
    ];

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * One statement.
     *
     * @throws DataImportNotFound
     */
    public function overview(string $importId, string $playerId): ImportPuzzlesOverview
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            throw new DataImportNotFound();
        }

        // "Already yours" is a hint until the row's puzzle is decided (pairing.md): a row counts when the result an
        // earlier import made still exists, or when the result on the profile is on the very puzzle the row's
        // puzzle was decided to be. A result counts whatever its state - one waiting for verification (suspicious)
        // is still the player's own and must not be offered again; the result is read by its id only.
        $query = <<<SQL
SELECT
    data_import.status,
    data_import.paired_at IS NOT NULL AS paired,
    counts.*,
    (
        SELECT COALESCE(SUM(sheet.rows_need_look), 0)
        FROM data_import_sheet sheet
        WHERE sheet.import_id = data_import.id
            AND sheet.parsed_version = sheet.mapping_version
    ) AS rows_need_look,
    (
        SELECT COUNT(*)
        FROM data_import_row import_row
        INNER JOIN puzzle_solving_time result ON result.id = import_row.already_yours_solving_time_id
        LEFT JOIN data_import_puzzle row_puzzle ON row_puzzle.id = import_row.import_puzzle_id
        WHERE import_row.import_id = data_import.id
            AND import_row.state = 'waiting'
            AND import_row.already_yours_how IS NOT NULL
            AND (
                import_row.already_yours_how = 'earlier_import'
                OR (row_puzzle.choice = 'catalogue' AND row_puzzle.puzzle_id = result.puzzle_id)
            )
    ) AS rows_already_yours
FROM data_import
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS puzzles,
        COUNT(*) FILTER (WHERE tier = 'found') AS found,
        COUNT(*) FILTER (WHERE tier = 'found' AND choice = 'undecided') AS found_open,
        COUNT(*) FILTER (WHERE tier = 'probably') AS probably,
        COUNT(*) FILTER (WHERE tier = 'probably' AND choice = 'undecided') AS probably_open,
        COUNT(*) FILTER (WHERE tier = 'choose') AS choose,
        COUNT(*) FILTER (WHERE tier = 'choose' AND choice = 'undecided') AS choose_open,
        COUNT(*) FILTER (WHERE tier = 'none') AS none,
        COUNT(*) FILTER (WHERE tier = 'none' AND choice = 'undecided') AS none_open,
        COUNT(*) FILTER (WHERE tier IS NULL AND choice <> 'undecided') AS remembered,
        COUNT(*) FILTER (WHERE tier IS NULL AND choice = 'undecided') AS searching,
        COUNT(*) FILTER (WHERE choice = 'unlisted') AS unlisted,
        COUNT(*) FILTER (WHERE choice = 'left_out') AS left_out
    FROM data_import_puzzle
    WHERE data_import_puzzle.import_id = data_import.id
) counts ON TRUE
WHERE data_import.id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> :discarded
SQL;

        /** @var false|array{status: string, paired: bool, puzzles: int, found: int, found_open: int, probably: int, probably_open: int, choose: int, choose_open: int, none: int, none_open: int, remembered: int, searching: int, unlisted: int, left_out: int, rows_need_look: int|string, rows_already_yours: int} $row */
        $row = $this->database->executeQuery($query, [
            'importId' => $importId,
            'playerId' => $playerId,
            'discarded' => DataImportStatus::Discarded->value,
        ])->fetchAssociative();

        if ($row === false) {
            throw new DataImportNotFound();
        }

        return new ImportPuzzlesOverview(
            beingSetUp: $row['status'] === DataImportStatus::SettingUp->value,
            puzzles: $row['puzzles'],
            groups: [
                'found' => ['total' => $row['found'], 'open' => $row['found_open']],
                'probably' => ['total' => $row['probably'], 'open' => $row['probably_open']],
                'choose' => ['total' => $row['choose'], 'open' => $row['choose_open']],
                'not-found' => ['total' => $row['none'], 'open' => $row['none_open']],
                'remembered' => ['total' => $row['remembered'], 'open' => 0],
            ],
            searching: $row['searching'],
            paired: $row['paired'],
            unlisted: $row['unlisted'],
            leftOut: $row['left_out'],
            rowsAlreadyYours: $row['rows_already_yours'],
            rowsNeedLook: (int) $row['rows_need_look'],
        );
    }

    /**
     * A page of one group, by the puzzles' names as the file writes them. One statement.
     *
     * @return list<ImportPuzzleCard>
     */
    public function page(string $importId, string $playerId, string $group, int $limit, int $offset): array
    {
        if (isset(self::GROUP_CONDITION[$group]) === false) {
            return [];
        }

        return $this->cards($importId, $playerId, self::GROUP_CONDITION[$group], [], $limit, $offset);
    }

    /**
     * One puzzle of the import with everything it could be. One statement.
     *
     * @throws DataImportNotFound
     */
    public function one(string $importId, string $playerId, string $importPuzzleId): ImportPuzzleCard
    {
        if (Uuid::isValid($importPuzzleId) === false) {
            throw new DataImportNotFound();
        }

        return $this->cards($importId, $playerId, 'import_puzzle.id = :importPuzzleId', ['importPuzzleId' => $importPuzzleId], 1, 0)[0]
            ?? throw new DataImportNotFound();
    }

    /**
     * The first rows of the file that are this puzzle, as they were understood. One statement.
     *
     * @return list<ImportSheetRow>
     */
    public function rowsOf(string $importId, string $playerId, string $importPuzzleId, int $limit): array
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false || Uuid::isValid($importPuzzleId) === false) {
            return [];
        }

        /** @var list<array{id: string, row_number: int, kind: string, parsed: null|string, problems: null|string, title: string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT stored.id, stored.row_number, stored.kind, stored.parsed, stored.problems, sheet.title
FROM data_import_row stored
INNER JOIN data_import_sheet sheet ON sheet.id = stored.sheet_id
INNER JOIN data_import ON data_import.id = stored.import_id
WHERE stored.import_puzzle_id = :importPuzzleId
    AND stored.import_id = :importId
    AND data_import.player_id = :playerId
ORDER BY sheet.position, stored.row_number
LIMIT :limit
SQL, ['importPuzzleId' => $importPuzzleId, 'importId' => $importId, 'playerId' => $playerId, 'limit' => $limit])->fetchAllAssociative();

        return array_map(self::row(...), $rows);
    }

    /**
     * The next puzzle of a group that waits for the player, in the lists' order, after a puzzle - or the first one.
     * One statement.
     */
    public function nextUndecided(string $importId, string $playerId, string $group, null|string $afterImportPuzzleId): null|string
    {
        if (isset(self::GROUP_CONDITION[$group]) === false || Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            return null;
        }

        $after = $afterImportPuzzleId !== null && Uuid::isValid($afterImportPuzzleId) ? $afterImportPuzzleId : null;
        $condition = self::GROUP_CONDITION[$group];

        $id = $this->database->executeQuery(<<<SQL
SELECT import_puzzle.id
FROM data_import_puzzle import_puzzle
INNER JOIN data_import ON data_import.id = import_puzzle.import_id
WHERE import_puzzle.import_id = :importId
    AND data_import.player_id = :playerId
    AND data_import.status <> :discarded
    AND {$condition}
    AND import_puzzle.choice = 'undecided'
    AND (
        CAST(:after AS UUID) IS NULL
        OR (lower(import_puzzle.name), import_puzzle.id) > (
            SELECT lower(shown.name), shown.id
            FROM data_import_puzzle shown
            WHERE shown.id = CAST(:after AS UUID)
                AND shown.import_id = :importId
        )
    )
ORDER BY lower(import_puzzle.name), import_puzzle.id
LIMIT 1
SQL, ['importId' => $importId, 'playerId' => $playerId, 'discarded' => DataImportStatus::Discarded->value, 'after' => $after])->fetchOne();

        return is_string($id) ? $id : null;
    }

    /**
     * What the player's own search finds in the catalogue - a name, a part of one, a barcode, a brand code
     * (PuzzleTextSearch, as the site's search) - among the puzzles that may be offered to them. The file's piece
     * count first among equally good matches. One statement; nothing for an empty search.
     *
     * @return list<ImportCataloguePuzzle>
     */
    public function search(string $playerId, string $typed, null|int $filePieces, null|string $fileManufacturerId = null): array
    {
        $textSearch = PuzzleTextSearch::fromUserInput(mb_substr($typed, 0, 200));

        if (self::isSearchable($typed) === false || $textSearch->isEmpty() || Uuid::isValid($playerId) === false) {
            return [];
        }

        $now = $this->clock->now();
        $candidate = GetImportPuzzleCandidates::sqlCandidate('puzzle');
        $condition = $textSearch->condition('puzzle');
        $score = $textSearch->score('puzzle');

        /** @var list<array{puzzle_id: string, puzzle_name: string, alternative_names: null|string, puzzle_pieces: int, image: null|string, image_ratio: null|float|string, manufacturer_id: null|string, brand_name: null|string}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_names,
    puzzle.pieces_count AS puzzle_pieces,
    puzzle.image,
    puzzle.image_ratio,
    puzzle.manufacturer_id,
    manufacturer.name AS brand_name
FROM puzzle
LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
WHERE {$candidate}
    AND {$condition}
ORDER BY
    {$score} DESC,
    (CAST(:filePieces AS INT) IS NOT NULL AND puzzle.pieces_count = CAST(:filePieces AS INT)) DESC,
    COALESCE(puzzle_statistics.solved_times_count, 0) DESC,
    puzzle.name,
    puzzle.id
LIMIT :limit
SQL, [
            'now' => $now->format('Y-m-d H:i:s'),
            'playerId' => $playerId,
            'filePieces' => $filePieces,
            'limit' => self::SEARCH_RESULTS,
            ...$textSearch->parameters($now),
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): ImportCataloguePuzzle => new ImportCataloguePuzzle(
            brandDiffers: self::brandDiffers($fileManufacturerId, $row['manufacturer_id']),
            puzzleId: $row['puzzle_id'],
            name: $row['puzzle_name'],
            alternativeNames: PuzzleNames::fromJson($row['alternative_names']),
            brandName: $row['brand_name'],
            piecesCount: $row['puzzle_pieces'],
            image: $row['image'],
            imageRatio: $row['image_ratio'] === null ? null : (float) $row['image_ratio'],
            how: 'search',
            piecesDiffer: $filePieces !== null && abs($row['puzzle_pieces'] - $filePieces) > 1,
        ), $rows);
    }

    /**
     * Whether a typed search is searched at all: two characters at least - or a number (a barcode). One letter
     * would be most of the catalogue.
     */
    public static function isSearchable(string $typed): bool
    {
        $typed = trim($typed);

        return mb_strlen($typed) >= self::SEARCH_MIN_CHARACTERS;
    }

    /**
     * One catalogue puzzle the player picked, as the screens show it - null when it may not be offered to them.
     * What "choose it anyway?" names. One statement.
     */
    public function offered(string $playerId, string $puzzleId, null|int $filePieces, null|string $fileManufacturerId): null|ImportCataloguePuzzle
    {
        if (Uuid::isValid($playerId) === false || Uuid::isValid($puzzleId) === false) {
            return null;
        }

        $candidate = GetImportPuzzleCandidates::sqlCandidate('puzzle');

        /** @var false|array{puzzle_id: string, puzzle_name: string, alternative_names: null|string, puzzle_pieces: int, image: null|string, image_ratio: null|float|string, manufacturer_id: null|string, brand_name: null|string} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_names,
    puzzle.pieces_count AS puzzle_pieces,
    puzzle.image,
    puzzle.image_ratio,
    puzzle.manufacturer_id,
    manufacturer.name AS brand_name
FROM puzzle
LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle.id = :puzzleId
    AND {$candidate}
SQL, ['puzzleId' => $puzzleId, 'playerId' => $playerId, 'now' => $this->clock->now()->format('Y-m-d H:i:s')])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return new ImportCataloguePuzzle(
            puzzleId: $row['puzzle_id'],
            name: $row['puzzle_name'],
            alternativeNames: PuzzleNames::fromJson($row['alternative_names']),
            brandName: $row['brand_name'],
            piecesCount: $row['puzzle_pieces'],
            image: $row['image'],
            imageRatio: $row['image_ratio'] === null ? null : (float) $row['image_ratio'],
            how: 'chosen',
            piecesDiffer: $filePieces !== null && abs($row['puzzle_pieces'] - $filePieces) > 1,
            brandDiffers: self::brandDiffers($fileManufacturerId, $row['manufacturer_id']),
        );
    }

    private static function brandDiffers(null|string $fileManufacturerId, null|string $puzzleManufacturerId): bool
    {
        return $fileManufacturerId !== null && $puzzleManufacturerId !== null && strtolower($fileManufacturerId) !== strtolower($puzzleManufacturerId);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<ImportPuzzleCard>
     */
    private function cards(string $importId, string $playerId, string $condition, array $parameters, int $limit, int $offset): array
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            return [];
        }

        $candidate = GetImportPuzzleCandidates::sqlCandidate('puzzle');

        $query = <<<SQL
WITH page AS (
    SELECT import_puzzle.*
    FROM data_import_puzzle import_puzzle
    INNER JOIN data_import ON data_import.id = import_puzzle.import_id
    WHERE import_puzzle.import_id = :importId
        AND data_import.player_id = :playerId
        AND data_import.status <> :discarded
        AND {$condition}
    ORDER BY lower(import_puzzle.name), import_puzzle.id
    LIMIT :limit OFFSET :offset
),
offered AS (
    -- What was decided comes first (position 0), then the candidates as the rules ordered them
    SELECT page.id AS import_puzzle_id, 0 AS position, page.puzzle_id, 'decided' AS how
    FROM page
    WHERE page.puzzle_id IS NOT NULL

    UNION ALL

    SELECT
        page.id,
        candidate.position,
        COALESCE(puzzle_redirect.survivor_puzzle_id, CAST(candidate.item ->> 'puzzleId' AS UUID)),
        candidate.item ->> 'how'
    FROM page
    CROSS JOIN LATERAL jsonb_array_elements(page.candidates) WITH ORDINALITY AS candidate(item, position)
    LEFT JOIN puzzle_redirect ON puzzle_redirect.old_puzzle_id = CAST(candidate.item ->> 'puzzleId' AS UUID)
)
SELECT
    page.id,
    page.name,
    page.brand_text,
    page.pieces_count,
    page.manufacturer_id AS said_manufacturer_id,
    page.rows_results,
    page.rows_owned,
    page.rows_wish_list,
    page.tier,
    page.choice,
    page.decided_how,
    page.decision_version,
    offered.position,
    offered.how,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_names,
    puzzle.pieces_count AS puzzle_pieces,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS image_ratio,
    puzzle.manufacturer_id AS puzzle_manufacturer_id,
    manufacturer.name AS brand_name
FROM page
LEFT JOIN offered ON offered.import_puzzle_id = page.id
LEFT JOIN puzzle
    ON puzzle.id = offered.puzzle_id
    -- A decided puzzle is shown as decided; a candidate only while it may be offered
    AND (offered.position = 0 OR {$candidate})
LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
ORDER BY lower(page.name), page.id, offered.position
SQL;

        /** @var list<array{id: string, name: string, brand_text: null|string, pieces_count: null|int, said_manufacturer_id: null|string, rows_results: int, rows_owned: int, rows_wish_list: int, tier: null|string, choice: string, decided_how: null|string, decision_version: int, position: null|int, how: null|string, puzzle_id: null|string, puzzle_name: null|string, alternative_names: null|string, puzzle_pieces: null|int, image: null|string, image_ratio: null|float|string, puzzle_manufacturer_id: null|string, brand_name: null|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'importId' => $importId,
            'playerId' => $playerId,
            'discarded' => DataImportStatus::Discarded->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'limit' => $limit,
            'offset' => $offset,
            ...$parameters,
        ])->fetchAllAssociative();

        $said = [];
        $offered = [];

        foreach ($rows as $row) {
            $said[$row['id']] ??= $row;

            if ($row['puzzle_id'] === null || $row['puzzle_name'] === null || $row['puzzle_pieces'] === null) {
                continue;
            }

            $how = $row['how'] ?? 'decided';

            if ($how === 'name' && $row['said_manufacturer_id'] !== null && $row['said_manufacturer_id'] === $row['puzzle_manufacturer_id']) {
                $how = 'name_brand';
            }

            $offered[$row['id']][] = [
                'decided' => $row['position'] === 0,
                'puzzle' => new ImportCataloguePuzzle(
                    puzzleId: $row['puzzle_id'],
                    name: $row['puzzle_name'],
                    alternativeNames: PuzzleNames::fromJson($row['alternative_names']),
                    brandName: $row['brand_name'],
                    piecesCount: $row['puzzle_pieces'],
                    image: $row['image'],
                    imageRatio: $row['image_ratio'] === null ? null : (float) $row['image_ratio'],
                    how: $how,
                    piecesDiffer: $row['pieces_count'] !== null && abs($row['puzzle_pieces'] - $row['pieces_count']) > 1,
                    brandDiffers: self::brandDiffers($row['said_manufacturer_id'], $row['puzzle_manufacturer_id']),
                ),
            ];
        }

        $cards = [];

        foreach ($said as $id => $row) {
            $decided = null;
            $candidates = [];

            foreach ($offered[$id] ?? [] as $offer) {
                $puzzle = $offer['puzzle'];

                if ($offer['decided']) {
                    $decided = $puzzle;
                } elseif (isset($candidates[$puzzle->puzzleId]) === false) {
                    $candidates[$puzzle->puzzleId] = $puzzle;
                }
            }

            if ($decided !== null) {
                // Why it is this one: as the rules found it - or the player's own choice, now or in an earlier import
                $found = $candidates[$decided->puzzleId] ?? null;
                $decided = new ImportCataloguePuzzle(
                    $decided->puzzleId,
                    $decided->name,
                    $decided->alternativeNames,
                    $decided->brandName,
                    $decided->piecesCount,
                    $decided->image,
                    $decided->imageRatio,
                    $found !== null ? $found->how : ($row['decided_how'] === 'remembered' ? 'remembered' : 'chosen'),
                    $decided->piecesDiffer,
                    $decided->brandDiffers,
                );
            }

            $cards[] = new ImportPuzzleCard(
                importPuzzleId: $id,
                name: $row['name'],
                brandText: $row['brand_text'],
                piecesCount: $row['pieces_count'],
                rowsResults: $row['rows_results'],
                rowsOwned: $row['rows_owned'],
                rowsWishList: $row['rows_wish_list'],
                tier: $row['tier'],
                choice: $row['choice'],
                decidedHow: $row['decided_how'],
                decisionVersion: $row['decision_version'],
                decided: $decided,
                candidates: array_values($candidates),
                manufacturerId: $row['said_manufacturer_id'],
            );
        }

        return $cards;
    }

    /**
     * @param array{id: string, row_number: int, kind: null|string, parsed: null|string, problems: null|string, title: string, ...} $row
     */
    public static function row(array $row): ImportSheetRow
    {
        $parsed = $row['parsed'] === null ? null : json_decode($row['parsed'], true);
        $stored = $row['problems'] === null ? null : json_decode($row['problems'], true);
        $problems = [];

        foreach (is_array($stored) ? $stored : [] as $problem) {
            if (is_array($problem) && is_string($problem['code'] ?? null)) {
                $problems[] = [
                    'field' => is_string($problem['field'] ?? null) ? $problem['field'] : '',
                    'code' => $problem['code'],
                    'value' => is_string($problem['value'] ?? null) ? $problem['value'] : '',
                    'waiting' => ($problem['waiting'] ?? false) === true,
                ];
            }
        }

        /** @var array<string, mixed> $parsed */
        $parsed = is_array($parsed) ? $parsed : [];

        return new ImportSheetRow($row['row_number'], $row['kind'] ?? 'not_a_puzzle', $parsed, $problems, $row['id'], $row['title']);
    }
}
