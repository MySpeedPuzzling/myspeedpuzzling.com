<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\UnsolvedPuzzleItem;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * "Solved" means a time of the player's own or one as a team member. Team membership is a jsonb
 * containment test so that custom_pst_team_puzzlers_gin can answer it - an EXISTS over
 * json_array_elements() scanned the team times of every puzzle in the collection (~370 ms for the
 * largest collection on prod). Both tests name the player by the :playerId parameter, not by
 * ci.player_id (equal, per the outer WHERE): only a constant lets the planner build the solved set
 * once from the player_id and GIN indexes instead of probing every collection puzzle.
 */
readonly final class GetUnsolvedPuzzles
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<UnsolvedPuzzleItem>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_names as puzzle_alternative_names,
    p.search_names,
    p.search_codes,
    p.pieces_count,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS image_ratio,
    m.name as manufacturer_name,
    MIN(ci.added_at) as added_at
FROM collection_item ci
JOIN puzzle p ON ci.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
WHERE ci.player_id = :playerId
  AND NOT EXISTS (
    SELECT 1 FROM puzzle_solving_time pst
    WHERE pst.puzzle_id = ci.puzzle_id
      AND (
        pst.player_id = :playerId
        OR (pst.team IS NOT NULL AND (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID))))
      )
  )
GROUP BY p.id, p.name, p.alternative_names, p.search_names, p.search_codes, p.pieces_count, m.name
ORDER BY added_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): UnsolvedPuzzleItem {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_names: string,
             *     search_names: null|string,
             *     search_codes: null|string,
             *     pieces_count: int,
             *     image: string|null,
             *     image_ratio: string|null,
             *     manufacturer_name: string|null,
             *     added_at: string,
             * } $row
             */

            return new UnsolvedPuzzleItem(
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeNames: PuzzleNames::fromJson($row['puzzle_alternative_names']),
                searchNames: $row['search_names'],
                searchCodes: $row['search_codes'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                imageRatio: $row['image_ratio'] !== null ? (float) $row['image_ratio'] : null,
                addedAt: new DateTimeImmutable($row['added_at']),
                isBorrowed: false,
                borrowedFromPlayerId: null,
                borrowedFromPlayerName: null,
            );
        }, $data);
    }

    public function countByPlayerId(string $playerId): int
    {
        // Count unique puzzles (not collection items) that haven't been solved
        $query = <<<SQL
SELECT COUNT(DISTINCT ci.puzzle_id) as item_count
FROM collection_item ci
WHERE ci.player_id = :playerId
  AND NOT EXISTS (
    SELECT 1 FROM puzzle_solving_time pst
    WHERE pst.puzzle_id = ci.puzzle_id
      AND (
        pst.player_id = :playerId
        OR (pst.team IS NOT NULL AND (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID))))
      )
  )
SQL;

        $result = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function byPuzzleIdAndPlayerId(string $puzzleId, string $playerId): null|UnsolvedPuzzleItem
    {
        $query = <<<SQL
SELECT
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_names as puzzle_alternative_names,
    p.search_names,
    p.search_codes,
    p.pieces_count,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS image_ratio,
    m.name as manufacturer_name,
    MIN(ci.added_at) as added_at
FROM collection_item ci
JOIN puzzle p ON ci.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
WHERE ci.player_id = :playerId
  AND ci.puzzle_id = :puzzleId
  AND NOT EXISTS (
    SELECT 1 FROM puzzle_solving_time pst
    WHERE pst.puzzle_id = ci.puzzle_id
      AND (
        pst.player_id = :playerId
        OR (pst.team IS NOT NULL AND (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID))))
      )
  )
GROUP BY p.id, p.name, p.alternative_names, p.search_names, p.search_codes, p.pieces_count, m.name
SQL;

        $data = $this->database
            ->executeQuery($query, ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'playerId' => $playerId, 'puzzleId' => $puzzleId])
            ->fetchAssociative();

        if ($data === false) {
            return null;
        }

        /** @var array{
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_names: string,
         *     search_names: null|string,
         *     search_codes: null|string,
         *     pieces_count: int,
         *     image: string|null,
         *     image_ratio: string|null,
         *     manufacturer_name: string|null,
         *     added_at: string,
         * } $data
         */

        return new UnsolvedPuzzleItem(
            puzzleId: $data['puzzle_id'],
            puzzleName: $data['puzzle_name'],
            puzzleAlternativeNames: PuzzleNames::fromJson($data['puzzle_alternative_names']),
            searchNames: $data['search_names'],
            searchCodes: $data['search_codes'],
            piecesCount: $data['pieces_count'],
            manufacturerName: $data['manufacturer_name'],
            image: $data['image'],
            imageRatio: $data['image_ratio'] !== null ? (float) $data['image_ratio'] : null,
            addedAt: new DateTimeImmutable($data['added_at']),
            isBorrowed: false,
            borrowedFromPlayerId: null,
            borrowedFromPlayerName: null,
        );
    }
}
