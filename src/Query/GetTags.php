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
     * over a series holding the same tag. A tag of drafts only is left out (sqlNotOnlyOfDrafts()).
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
        $visibleSeries = IsSeriesPubliclyVisible::SQL_CONDITION;
        $notOnlyOfDrafts = self::sqlNotOnlyOfDrafts('tag');

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
            AND {$visibleSeries}
    ) candidate
    ORDER BY candidate.is_series, candidate.date_from DESC NULLS LAST, candidate.name
    LIMIT 1
) linked ON true
WHERE tag_puzzle.puzzle_id = :puzzleId
    AND {$notOnlyOfDrafts}
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
     * Every tag but those of drafts only (sqlNotOnlyOfDrafts())
     *
     * @return array<PuzzleTag>
     */
    public function all(): array
    {
        $notOnlyOfDrafts = self::sqlNotOnlyOfDrafts('tag');

        $query = <<<SQL
SELECT tag.id AS tag_id, tag.name
FROM tag
WHERE {$notOnlyOfDrafts}
ORDER BY tag.name
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
     * The tags of each puzzle - but those of drafts only (sqlNotOnlyOfDrafts())
     *
     * @param null|list<string> $onlyPuzzleIds
     *
     * @return array<string, array<PuzzleTag>>
     */
    public function allGroupedPerPuzzle(null|array $onlyPuzzleIds = null): array
    {
        $whereClause = 'WHERE ' . self::sqlNotOnlyOfDrafts('tag');
        $params = [];
        $types = [];

        if ($onlyPuzzleIds !== null) {
            $whereClause .= ' AND tag_puzzle.puzzle_id IN (:puzzleIds)';
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

    /**
     * A competition's tag is named after it (SetCompetitionPuzzles), so a tag that belongs to drafts only would tell a
     * draft's name: it is left out everywhere until one of them is published (docs/features/organizations/README.md
     * "Drafts"). A tag of no event, or of any event or series that is not a draft, stays - an event waiting for approval
     * keeps its plain badge as before. Alias of the tag row: $tag.
     */
    private static function sqlNotOnlyOfDrafts(string $tag): string
    {
        return <<<SQL
(
    (
        NOT EXISTS (
            SELECT 1
            FROM competition draft_c
            LEFT JOIN competition_series draft_cs ON draft_cs.id = draft_c.series_id
            WHERE draft_c.tag_id = {$tag}.id AND (draft_c.is_draft OR COALESCE(draft_cs.is_draft, false))
        )
        AND NOT EXISTS (SELECT 1 FROM competition_series draft_s WHERE draft_s.tag_id = {$tag}.id AND draft_s.is_draft)
    )
    OR EXISTS (
        SELECT 1
        FROM competition shown_c
        LEFT JOIN competition_series shown_cs ON shown_cs.id = shown_c.series_id
        WHERE shown_c.tag_id = {$tag}.id AND shown_c.is_draft = false AND COALESCE(shown_cs.is_draft, false) = false
    )
    OR EXISTS (SELECT 1 FROM competition_series shown_s WHERE shown_s.tag_id = {$tag}.id AND shown_s.is_draft = false)
)
SQL;
    }
}
