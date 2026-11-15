<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Results\Export\ExportablePuzzle;
use SpeedPuzzling\Web\Results\MyData\MyDataPage;
use SpeedPuzzling\Web\Results\MyData\MyDataPuzzleRow;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;
use SpeedPuzzling\Web\Value\MyDataCriteria;
use SpeedPuzzling\Web\Value\MyDataSort;
use SpeedPuzzling\Web\Value\MyDataTab;

/**
 * The puzzles of the signed-in player's collections - the main one and the custom ones, private ones and those of a
 * lapsed membership included - and their wish list, as a table (docs/features/my-data.md). Own data, like the export:
 * the caller passes the id of the player who is signed in, never an id from a URL.
 *
 * The items' columns are named one by one - what the owner wrote into their own fields on an item is read by
 * the viewer-bound reader of those values alone, never here. "Solved N× · best time" comes from the export's one aggregate
 * (GetExportablePuzzleSolveSummary) over the page's puzzles: two statements per page whatever the size of the library.
 */
readonly final class GetMyDataPuzzles
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private GetExportablePuzzleSolveSummary $getSolveSummary,
        private string $uploadedAssetsBaseUrl,
    ) {
    }

    /**
     * @return array{collectionItems: int, wishList: int} how many rows each of the two tables has at all
     */
    public function facts(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return ['collectionItems' => 0, 'wishList' => 0];
        }

        $query = <<<SQL
SELECT
    (SELECT COUNT(*) FROM collection_item ci WHERE ci.player_id = :playerId) AS collection_items,
    (SELECT COUNT(*) FROM wish_list_item wli WHERE wli.player_id = :playerId) AS wish_list
SQL;

        /** @var false|array{collection_items: int|string, wish_list: int|string} $row */
        $row = $this->database->executeQuery($query, ['playerId' => $playerId])->fetchAssociative();

        return [
            'collectionItems' => $row === false ? 0 : (int) $row['collection_items'],
            'wishList' => $row === false ? 0 : (int) $row['wish_list'],
        ];
    }

    /**
     * @return MyDataPage<MyDataPuzzleRow>
     */
    public function page(string $playerId, MyDataCriteria $criteria): MyDataPage
    {
        if (Uuid::isValid($playerId) === false) {
            return new MyDataPage([], 0, 1);
        }

        $page = $this->fetch($playerId, $criteria);

        // A page behind the last row (an old link, a row removed meanwhile): the first page instead of an empty one
        if ($page->rows === [] && $criteria->page > 1) {
            $page = $this->fetch($playerId, $criteria->onPage(1));
        }

        return $page;
    }

    /**
     * @return MyDataPage<MyDataPuzzleRow>
     */
    private function fetch(string $playerId, MyDataCriteria $criteria): MyDataPage
    {
        $now = $this->clock->now();
        $wishList = $criteria->tab === MyDataTab::WishList;
        $conditions = ['item.player_id = :playerId'];
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

        if ($wishList === false && $criteria->collectionId === Collection::SYSTEM_ID) {
            $conditions[] = 'item.collection_id IS NULL';
        } elseif ($wishList === false && $criteria->collectionId !== null) {
            $conditions[] = 'item.collection_id = :collectionId';
            $parameters['collectionId'] = $criteria->collectionId;
        }

        if ($wishList === false && $criteria->withComment) {
            $conditions[] = "item.comment IS NOT NULL AND btrim(item.comment) <> ''";
        }

        $where = implode("\n    AND ", $conditions);
        $direction = $criteria->descending ? 'DESC' : 'ASC';

        // Every order ends on the item's id: equal values never straddle two pages
        $orderBy = match ($criteria->sort) {
            MyDataSort::Pieces => "p.pieces_count {$direction}, p.name, item.id",
            MyDataSort::Name => "p.name {$direction}, item.id",
            MyDataSort::Brand => "m.name {$direction} NULLS LAST, p.name, item.id",
            default => "item.added_at {$direction}, item.id",
        };

        $puzzleColumns = ExportablePuzzle::SQL_COLUMNS;
        $limit = MyDataCriteria::PAGE_SIZE;
        $offset = $criteria->offset();

        // A wish list row has no collection, comment or lending - the same shape, so one mapper serves both
        $itemColumns = $wishList
            ? <<<SQL
    NULL AS collection_id,
    NULL AS collection_name,
    NULL AS comment,
    false AS lent_out,
SQL
            : <<<SQL
    item.collection_id,
    c.name AS collection_name,
    item.comment,
    EXISTS (
        SELECT 1
        FROM lent_puzzle lp
        WHERE lp.owner_player_id = :playerId
            AND lp.puzzle_id = item.puzzle_id
    ) AS lent_out,
SQL;

        $from = $wishList
            ? 'wish_list_item item'
            : "collection_item item\nLEFT JOIN collection c ON c.id = item.collection_id";

        $query = <<<SQL
SELECT
    item.id AS item_id,
    item.added_at,
{$itemColumns}
    {$puzzleColumns},
    COUNT(*) OVER () AS total
FROM {$from}
INNER JOIN puzzle p ON p.id = item.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE {$where}
ORDER BY {$orderBy}
LIMIT {$limit} OFFSET {$offset}
SQL;

        $rows = $this->database->executeQuery($query, $parameters)->fetchAllAssociative();

        /** @var list<string> $puzzleIds */
        $puzzleIds = array_column($rows, 'puzzle_id');
        $solved = $this->getSolveSummary->forPuzzles($playerId, $puzzleIds);

        $items = [];
        $total = 0;

        foreach ($rows as $row) {
            $puzzle = ExportablePuzzle::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl);

            if ($puzzle === null) {
                continue;
            }

            /** @var int|string $rowTotal */
            $rowTotal = $row['total'];
            $total = (int) $rowTotal;
            $items[] = MyDataPuzzleRow::fromDatabaseRow($row, $puzzle, $solved[$puzzle->puzzleId] ?? null);
        }

        return new MyDataPage($items, $total, $criteria->page);
    }
}
