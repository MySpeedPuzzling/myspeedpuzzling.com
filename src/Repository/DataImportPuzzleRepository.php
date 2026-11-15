<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\ImportPuzzleDecidedHow;

/**
 * The distinct puzzles of an import (docs/features/data-import/pairing.md).
 *
 * **The pairing writes them in bulk, in native SQL - on purpose**, the exception CLAUDE.md allows with its reason: an
 * import holds up to 50,000 rows and thousands of distinct puzzles, built and matched again after every parse of a
 * sheet. Loaded as objects that is a statement per puzzle under the import's lock; here every step is one statement
 * whatever the import's size (ImportPuzzleMatcherTest pins the count). Nothing of it touches a table outside the
 * import. A **decision of the player** is never written here - that is the entity's (DecideImportPuzzlesHandler).
 */
readonly final class DataImportPuzzleRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, DataImportPuzzle> by id - only those of the import
     */
    public function ofImportByIds(DataImport $import, array $ids): array
    {
        $ids = array_values(array_filter($ids, Uuid::isValid(...)));

        if ($ids === []) {
            return [];
        }

        /** @var list<DataImportPuzzle> $puzzles */
        $puzzles = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(DataImportPuzzle::class, 'p')
            ->where('p.import = :import')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('import', $import)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];

        foreach ($puzzles as $puzzle) {
            $byId[$puzzle->id->toString()] = $puzzle;
        }

        return $byId;
    }

    /**
     * Every import's puzzle decided to be this catalogue puzzle - for a puzzle merge.
     *
     * @return list<DataImportPuzzle>
     */
    public function decidedToBe(Puzzle $puzzle): array
    {
        return $this->entityManager->getRepository(DataImportPuzzle::class)->findBy(['puzzle' => $puzzle]);
    }

    /**
     * What the import's rows say about each distinct puzzle key: the first row's name, brand, piece count and own
     * puzzle id (first by sheet, then by row), every row's codes, how many rows of each kind. Rows that are no puzzle
     * or are left out do not count.
     *
     * @return list<array{puzzleKey: string, name: null|string, brandText: null|string, pieces: null|int, ownPuzzleId: null|string, eans: list<string>, brandCodes: list<string>, rowsResults: int, rowsOwned: int, rowsWishList: int}>
     */
    public function distinctPuzzlesOfRows(DataImport $import): array
    {
        /** @var list<array{puzzle_key: string, name: null|string, brand_text: null|string, pieces: null|string, own_puzzle_id: null|string, eans: null|string, brand_codes: null|string, rows_results: int, rows_owned: int, rows_wish_list: int}> $rows */
        $rows = $this->entityManager->getConnection()->executeQuery(<<<SQL
SELECT
    data_import_row.puzzle_key,
    (array_agg(data_import_row.parsed->>'name' ORDER BY sheet.position, data_import_row.row_number) FILTER (WHERE data_import_row.parsed->>'name' IS NOT NULL))[1] AS name,
    (array_agg(data_import_row.parsed->>'brand' ORDER BY sheet.position, data_import_row.row_number) FILTER (WHERE data_import_row.parsed->>'brand' IS NOT NULL))[1] AS brand_text,
    (array_agg(data_import_row.parsed->>'pieces' ORDER BY sheet.position, data_import_row.row_number) FILTER (WHERE data_import_row.parsed->>'pieces' IS NOT NULL))[1] AS pieces,
    (array_agg(data_import_row.parsed->>'puzzleId' ORDER BY sheet.position, data_import_row.row_number) FILTER (WHERE data_import_row.parsed->>'puzzleId' IS NOT NULL))[1] AS own_puzzle_id,
    jsonb_agg(DISTINCT data_import_row.parsed->'barcodes') FILTER (WHERE jsonb_typeof(data_import_row.parsed->'barcodes') = 'array') AS eans,
    jsonb_agg(DISTINCT data_import_row.parsed->'brandCodes') FILTER (WHERE jsonb_typeof(data_import_row.parsed->'brandCodes') = 'array') AS brand_codes,
    COUNT(*) FILTER (WHERE data_import_row.kind IN (:result, :both)) AS rows_results,
    COUNT(*) FILTER (WHERE data_import_row.kind IN (:owned, :both)) AS rows_owned,
    COUNT(*) FILTER (WHERE data_import_row.kind = :wishList) AS rows_wish_list
FROM data_import_row
INNER JOIN data_import_sheet sheet ON sheet.id = data_import_row.sheet_id
WHERE data_import_row.import_id = :importId
    AND data_import_row.puzzle_key IS NOT NULL
    AND data_import_row.kind IN (:result, :owned, :both, :wishList)
    AND data_import_row.state <> :leftOut
GROUP BY data_import_row.puzzle_key
ORDER BY data_import_row.puzzle_key
SQL, self::rowParameters($import))->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'puzzleKey' => $row['puzzle_key'],
            'name' => $row['name'],
            'brandText' => $row['brand_text'],
            'pieces' => $row['pieces'] !== null && preg_match('/^[0-9]{1,6}$/', $row['pieces']) === 1 && (int) $row['pieces'] > 0 ? (int) $row['pieces'] : null,
            'ownPuzzleId' => $row['own_puzzle_id'] !== null && Uuid::isValid($row['own_puzzle_id']) ? strtolower($row['own_puzzle_id']) : null,
            'eans' => self::flatTexts($row['eans']),
            'brandCodes' => self::flatTexts($row['brand_codes']),
            'rowsResults' => $row['rows_results'],
            'rowsOwned' => $row['rows_owned'],
            'rowsWishList' => $row['rows_wish_list'],
        ], $rows);
    }

    /**
     * Stores what distinctPuzzlesOfRows() found: a new key is a new puzzle (under the id given - made in PHP, a
     * UUIDv7 like every id), a known key keeps its row, its decision and what the rules said, and takes what the
     * file says now. One statement.
     *
     * @param list<array{id: string, puzzleKey: string, name: string, brandText: null|string, pieces: null|int, ownPuzzleId: null|string, eans: list<string>, brandCodes: list<string>, rowsResults: int, rowsOwned: int, rowsWishList: int}> $puzzles
     */
    public function store(DataImport $import, array $puzzles, DateTimeImmutable $now): void
    {
        if ($puzzles === []) {
            return;
        }

        $this->entityManager->getConnection()->executeStatement(<<<SQL
INSERT INTO data_import_puzzle (id, import_id, puzzle_key, name, brand_text, pieces_count, own_puzzle_id, eans, brand_codes, rows_results, rows_owned, rows_wish_list, created_at)
SELECT said.id, CAST(:importId AS UUID), said."puzzleKey", said.name, said."brandText", said.pieces, said."ownPuzzleId", said.eans, said."brandCodes", said."rowsResults", said."rowsOwned", said."rowsWishList", CAST(:now AS TIMESTAMP)
FROM jsonb_to_recordset(CAST(:puzzles AS JSONB)) AS said(id UUID, "puzzleKey" TEXT, name TEXT, "brandText" TEXT, pieces INT, "ownPuzzleId" UUID, eans JSONB, "brandCodes" JSONB, "rowsResults" INT, "rowsOwned" INT, "rowsWishList" INT)
ON CONFLICT (import_id, puzzle_key) DO UPDATE SET
    name = EXCLUDED.name,
    brand_text = EXCLUDED.brand_text,
    pieces_count = EXCLUDED.pieces_count,
    own_puzzle_id = EXCLUDED.own_puzzle_id,
    eans = EXCLUDED.eans,
    brand_codes = EXCLUDED.brand_codes,
    rows_results = EXCLUDED.rows_results,
    rows_owned = EXCLUDED.rows_owned,
    rows_wish_list = EXCLUDED.rows_wish_list
SQL, [
            'importId' => $import->id->toString(),
            'puzzles' => json_encode($puzzles, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Puzzles no row of the import says any more (a mapping changed, a sheet was left out) go - decided or not: what
     * the player decided is remembered per puzzle key (import_puzzle_decision), so a key that comes back arrives
     * decided.
     */
    public function deleteWithoutRows(DataImport $import): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(<<<SQL
DELETE FROM data_import_puzzle
WHERE data_import_puzzle.import_id = :importId
    AND NOT EXISTS (
        SELECT 1
        FROM data_import_row
        WHERE data_import_row.import_id = data_import_puzzle.import_id
            AND data_import_row.puzzle_key = data_import_puzzle.puzzle_key
            AND data_import_row.kind IN (:result, :owned, :both, :wishList)
            AND data_import_row.state <> :leftOut
    )
SQL, self::rowParameters($import));
    }

    /**
     * Every row of the import names its distinct puzzle - or none, when its key has none.
     */
    public function linkRows(DataImport $import): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(<<<SQL
UPDATE data_import_row
SET import_puzzle_id = found.import_puzzle_id
FROM (
    SELECT data_import_row.id, data_import_puzzle.id AS import_puzzle_id
    FROM data_import_row
    LEFT JOIN data_import_puzzle
        ON data_import_puzzle.import_id = data_import_row.import_id
        AND data_import_puzzle.puzzle_key = data_import_row.puzzle_key
    WHERE data_import_row.import_id = :importId
) AS found
WHERE data_import_row.id = found.id
    AND data_import_row.import_puzzle_id IS DISTINCT FROM found.import_puzzle_id
SQL, ['importId' => $import->id->toString()]);
    }

    /**
     * The player's remembered decisions (import_puzzle_decision) arrive on every puzzle of the import the player has
     * not decided in this import: the same key → the same decision, as `remembered`, with the moment it was made. A
     * remembered catalogue puzzle that may not be a candidate any more is not applied. And a `remembered` decision
     * whose source is gone or changed is taken off again. "Not this puzzle" is no decision to apply - the rules read
     * it (forTheRules()).
     */
    public function applyRememberedDecisions(DataImport $import, DateTimeImmutable $now): void
    {
        $candidate = GetImportPuzzleCandidates::sqlCandidate('puzzle');
        $parameters = [
            'importId' => $import->id->toString(),
            'playerId' => $import->player->id->toString(),
            'now' => $now->format('Y-m-d H:i:s'),
            'player' => ImportPuzzleDecidedHow::Player->value,
            'remembered' => ImportPuzzleDecidedHow::Remembered->value,
            'catalogue' => ImportPuzzleChoice::Catalogue->value,
            'undecided' => ImportPuzzleChoice::Undecided->value,
            'notThis' => ImportPuzzleChoice::NotThisPuzzle->value,
        ];
        $valid = <<<SQL
    SELECT decision.puzzle_key, decision.choice, decision.puzzle_id, decision.decided_at
    FROM import_puzzle_decision decision
    LEFT JOIN puzzle ON puzzle.id = decision.puzzle_id
    WHERE decision.player_id = :playerId
        AND decision.choice <> :notThis
        AND (decision.choice <> :catalogue OR (puzzle.id IS NOT NULL AND {$candidate}))
SQL;
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement(<<<SQL
UPDATE data_import_puzzle
SET choice = :undecided, puzzle_id = NULL, decided_at = NULL, decided_how = NULL, decision_version = decision_version + 1
WHERE data_import_puzzle.import_id = :importId
    AND data_import_puzzle.decided_how = :remembered
    AND NOT EXISTS (
        SELECT 1
        FROM ({$valid}) AS valid
        WHERE valid.puzzle_key = data_import_puzzle.puzzle_key
    )
SQL, $parameters);

        $connection->executeStatement(<<<SQL
UPDATE data_import_puzzle
SET choice = valid.choice, puzzle_id = valid.puzzle_id, decided_at = valid.decided_at, decided_how = :remembered, decision_version = decision_version + 1
FROM ({$valid}) AS valid
WHERE data_import_puzzle.import_id = :importId
    AND valid.puzzle_key = data_import_puzzle.puzzle_key
    AND data_import_puzzle.decided_how IS DISTINCT FROM :player
    AND (
        data_import_puzzle.decided_how IS DISTINCT FROM :remembered
        OR data_import_puzzle.choice <> valid.choice
        OR data_import_puzzle.puzzle_id IS DISTINCT FROM valid.puzzle_id
        OR data_import_puzzle.decided_at IS DISTINCT FROM valid.decided_at
    )
SQL, $parameters);
    }

    /**
     * The puzzles the rules decide about: those neither the player nor a remembered decision has decided - each
     * with the catalogue puzzle the player once took back for its key ("not this puzzle"), if any.
     *
     * @return list<array{id: string, name: string, brandText: null|string, pieces: null|int, ownPuzzleId: null|string, eans: list<string>, brandCodes: list<string>, searched: bool, choice: string, puzzleId: null|string, decidedAt: null|string, notThisPuzzleId: null|string}>
     */
    public function forTheRules(DataImport $import): array
    {
        /** @var list<array{id: string, name: string, brand_text: null|string, pieces_count: null|int, own_puzzle_id: null|string, eans: string, brand_codes: string, searched: bool, choice: string, puzzle_id: null|string, decided_at: null|string, not_this_puzzle_id: null|string}> $rows */
        $rows = $this->entityManager->getConnection()->executeQuery(<<<SQL
SELECT
    data_import_puzzle.id, data_import_puzzle.name, data_import_puzzle.brand_text, data_import_puzzle.pieces_count,
    data_import_puzzle.own_puzzle_id, data_import_puzzle.eans, data_import_puzzle.brand_codes,
    data_import_puzzle.searched_at IS NOT NULL AS searched, data_import_puzzle.choice, data_import_puzzle.puzzle_id,
    data_import_puzzle.decided_at, taken_back.puzzle_id AS not_this_puzzle_id
FROM data_import_puzzle
LEFT JOIN import_puzzle_decision taken_back
    ON taken_back.player_id = :playerId
    AND taken_back.puzzle_key = data_import_puzzle.puzzle_key
    AND taken_back.choice = :notThis
WHERE data_import_puzzle.import_id = :importId
    AND (data_import_puzzle.decided_how IS NULL OR data_import_puzzle.decided_how = :automatic)
ORDER BY data_import_puzzle.id
SQL, [
            'importId' => $import->id->toString(),
            'playerId' => $import->player->id->toString(),
            'automatic' => ImportPuzzleDecidedHow::Automatic->value,
            'notThis' => ImportPuzzleChoice::NotThisPuzzle->value,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'brandText' => $row['brand_text'],
            'pieces' => $row['pieces_count'],
            'ownPuzzleId' => $row['own_puzzle_id'],
            'eans' => self::flatTexts($row['eans']),
            'brandCodes' => self::flatTexts($row['brand_codes']),
            'searched' => $row['searched'],
            'choice' => $row['choice'],
            'puzzleId' => $row['puzzle_id'],
            'decidedAt' => $row['decided_at'],
            'notThisPuzzleId' => $row['not_this_puzzle_id'],
        ], $rows);
    }

    /**
     * What the rules said about each puzzle handed in - one statement. Only puzzles the player has not decided
     * meanwhile are written (the pairing runs under the import's lock, like a decision: this is the second net).
     *
     * @param list<array{id: string, manufacturerId: null|string, tier: null|string, candidates: list<array<string, mixed>>, keepSearch: bool, choice: string, puzzleId: null|string, decidedAt: null|string, decidedHow: null|string}> $results
     *        `keepSearch`: the rules found nothing exact and the similar-name search ran before - what it found stays
     */
    public function storeRulesResults(DataImport $import, array $results): void
    {
        if ($results === []) {
            return;
        }

        $this->entityManager->getConnection()->executeStatement(<<<SQL
UPDATE data_import_puzzle
SET manufacturer_id = said."manufacturerId",
    tier = CASE WHEN said."keepSearch" THEN data_import_puzzle.tier ELSE said.tier END,
    candidates = CASE WHEN said."keepSearch" THEN data_import_puzzle.candidates ELSE said.candidates END,
    searched_at = CASE WHEN said."keepSearch" THEN data_import_puzzle.searched_at ELSE NULL END,
    choice = said.choice,
    puzzle_id = said."puzzleId",
    decided_at = said."decidedAt",
    decided_how = said."decidedHow",
    decision_version = data_import_puzzle.decision_version + CASE
        WHEN data_import_puzzle.choice IS DISTINCT FROM said.choice
            OR data_import_puzzle.puzzle_id IS DISTINCT FROM said."puzzleId"
            OR data_import_puzzle.decided_how IS DISTINCT FROM said."decidedHow"
        THEN 1 ELSE 0 END
FROM jsonb_to_recordset(CAST(:results AS JSONB)) AS said(id UUID, "manufacturerId" UUID, tier TEXT, candidates JSONB, "keepSearch" BOOLEAN, choice TEXT, "puzzleId" UUID, "decidedAt" TIMESTAMP, "decidedHow" TEXT)
WHERE data_import_puzzle.id = said.id
    AND data_import_puzzle.import_id = :importId
    AND (data_import_puzzle.decided_how IS NULL OR data_import_puzzle.decided_how = :automatic)
SQL, [
            'importId' => $import->id->toString(),
            'results' => json_encode($results, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'automatic' => ImportPuzzleDecidedHow::Automatic->value,
        ]);
    }

    /**
     * The next puzzles of the import that wait for the similar-name search: nothing exact was found, nobody decided.
     *
     * @return list<array{id: string, name: string, pieces: null|int, manufacturerId: null|string}>
     */
    public function waitingForSearch(DataImport $import, int $limit): array
    {
        /** @var list<array{id: string, name: string, pieces_count: null|int, manufacturer_id: null|string}> $rows */
        $rows = $this->entityManager->getConnection()->executeQuery(<<<SQL
SELECT id, name, pieces_count, manufacturer_id
FROM data_import_puzzle
WHERE import_id = :importId
    AND tier IS NULL
    AND searched_at IS NULL
    AND decided_how IS NULL
ORDER BY id
LIMIT :limit
SQL, ['importId' => $import->id->toString(), 'limit' => $limit])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'pieces' => $row['pieces_count'],
            'manufacturerId' => $row['manufacturer_id'],
        ], $rows);
    }

    /**
     * What the similar-name search found for a chunk - one statement, only for puzzles still waiting for it.
     *
     * @param list<array{id: string, tier: string, candidates: list<array<string, mixed>>}> $results
     */
    public function storeSearchResults(DataImport $import, array $results, DateTimeImmutable $now): void
    {
        if ($results === []) {
            return;
        }

        $this->entityManager->getConnection()->executeStatement(<<<SQL
UPDATE data_import_puzzle
SET tier = said.tier, candidates = said.candidates, searched_at = :now
FROM jsonb_to_recordset(CAST(:results AS JSONB)) AS said(id UUID, tier TEXT, candidates JSONB)
WHERE data_import_puzzle.id = said.id
    AND data_import_puzzle.import_id = :importId
    AND data_import_puzzle.tier IS NULL
    AND data_import_puzzle.decided_how IS NULL
SQL, [
            'importId' => $import->id->toString(),
            'results' => json_encode($results, JSON_THROW_ON_ERROR),
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function rowParameters(DataImport $import): array
    {
        return [
            'importId' => $import->id->toString(),
            'result' => DataImportRowKind::Result->value,
            'owned' => DataImportRowKind::Owned->value,
            'both' => DataImportRowKind::Both->value,
            'wishList' => DataImportRowKind::WishList->value,
            'leftOut' => DataImportRowState::LeftOut->value,
        ];
    }

    /**
     * Texts of a JSON list, or of a list of lists - each once, in a stable order.
     *
     * @return list<string>
     */
    private static function flatTexts(null|string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $texts = [];
        $decoded = json_decode($json, true);

        if (is_array($decoded) === false) {
            return [];
        }

        array_walk_recursive($decoded, static function (mixed $value) use (&$texts): void {
            if (is_string($value) && $value !== '') {
                $texts[$value] = true;
            } elseif (is_int($value)) {
                $texts[(string) $value] = true;
            }
        });

        $texts = array_map(strval(...), array_keys($texts));
        sort($texts, SORT_STRING);

        return $texts;
    }
}
