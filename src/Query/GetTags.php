<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleTag;

readonly final class GetTags
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * With the publicly visible competition each tag belongs to, so the puzzle page can link a tag badge to its
     * event instead of a filter URL. A tag of several competitions links the latest one; a competition wins
     * over a series holding the same tag.
     *
     * @throws PuzzleNotFound
     * @return array<PuzzleTag>
     */
    public function forPuzzle(string $puzzleId): array
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $visibleCompetition = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT
  tag.id AS tag_id,
  tag.name,
  linked.name AS competition_name,
  linked.slug AS competition_slug,
  linked.series_name AS competition_series_name,
  linked.series_slug AS competition_series_slug,
  linked.is_series AS competition_is_series
FROM tag_puzzle
INNER JOIN tag ON tag.id = tag_puzzle.tag_id
LEFT JOIN LATERAL (
    SELECT candidate.name, candidate.slug, candidate.series_name, candidate.series_slug, candidate.is_series
    FROM (
        SELECT c.name, c.slug, cs.name AS series_name, cs.slug AS series_slug, false AS is_series, c.date_from
        FROM competition c
        LEFT JOIN competition_series cs ON cs.id = c.series_id
        WHERE c.tag_id = tag.id
            AND {$visibleCompetition}
        UNION ALL
        SELECT cs.name, cs.slug, NULL, NULL, true, NULL
        FROM competition_series cs
        WHERE cs.tag_id = tag.id
            AND cs.approved_at IS NOT NULL
            AND cs.rejected_at IS NULL
    ) candidate
    ORDER BY candidate.is_series, candidate.date_from DESC NULLS LAST, candidate.name
    LIMIT 1
) linked ON true
WHERE tag_puzzle.puzzle_id = :puzzleId
ORDER BY tag.name
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'puzzleId' => $puzzleId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleTag {
            /**
             * @var array{
             *     tag_id: string,
             *     name: string,
             *     competition_name: null|string,
             *     competition_slug: null|string,
             *     competition_series_name: null|string,
             *     competition_series_slug: null|string,
             *     competition_is_series: null|bool,
             * } $row
             */

            return PuzzleTag::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<PuzzleTag>
     */
    public function all(): array
    {
        $query = <<<SQL
SELECT id AS tag_id, name
FROM tag
ORDER BY name
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function (array $row): PuzzleTag {
            /**
             * @var array{
             *     tag_id: string,
             *     name: string,
             * } $row
             */

            return PuzzleTag::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @param null|list<string> $onlyPuzzleIds
     *
     * @return array<string, array<PuzzleTag>>
     */
    public function allGroupedPerPuzzle(null|array $onlyPuzzleIds = null): array
    {
        $whereClause = '';
        $params = [];
        $types = [];

        if ($onlyPuzzleIds !== null) {
            $whereClause = 'WHERE tag_puzzle.puzzle_id IN (:puzzleIds)';
            $params['puzzleIds'] = $onlyPuzzleIds;
            $types['puzzleIds'] = ArrayParameterType::STRING;
        }

        $query = <<<SQL
SELECT
  tag.id AS tag_id,
  tag.name,
  puzzle_id
FROM tag
LEFT JOIN tag_puzzle ON tag.id = tag_puzzle.tag_id
{$whereClause}
ORDER BY tag.name
SQL;

        $data = [];
        $results = $this->database
            ->executeQuery($query, $params, $types)
            ->fetchAllAssociative();

        foreach ($results as $row) {
            /**
             * @var array{
             *     puzzle_id: null|string,
             *     tag_id: string,
             *     name: string,
             * } $row
             */

            if ($row['puzzle_id'] === null) {
                continue;
            }

            $data[$row['puzzle_id']][] = PuzzleTag::fromDatabaseRow($row);
        }

        return $data;
    }
}
