<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\BrandNeedingImprovement;
use SpeedPuzzling\Web\Results\PuzzleImprovementHint;
use SpeedPuzzling\Web\Results\PuzzleNeedingImprovement;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\ImprovementFilter;
use SpeedPuzzling\Web\Value\ImprovementNeed;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * Approved, visible puzzles players can help complete (docs/features/puzzles-needing-improvement.md): without an EAN,
 * or with a picture the image pipeline's `crop_trust` analysis marks as needing a better one (`resolution` set), and -
 * for admins and community moderators only - a picture that is not the puzzle's picture at all (`notThePicture`). An
 * analysis counts only while it describes the puzzle's current picture (its fingerprint). The most solved first: those
 * are the puzzles people scan and search most.
 *
 * Performance (production-sized copy, 2026-11-23, numbers in the doc): the filter joins only the analyses that mark
 * something (~5 500 of ~41 000) and by the puzzle id alone, the fingerprint is compared for those rows only - joining
 * every analysis on the fingerprint hashed md5() of every picture and read every payload (~70 ms a statement). The
 * badges are computed for the page's rows only, after the cut. The EAN filter joins no analysis at all.
 */
readonly final class GetPuzzlesNeedingImprovement
{
    public const int BRAND_FILTER_MIN_PUZZLES = 3;

    private const string VISIBLE = <<<SQL
puzzle.approved = true
    AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
SQL;

    private const string EAN_MISSING = "(puzzle.ean IS NULL OR puzzle.ean = '')";

    // The marks of a crop_trust payload (%s = the analysis' alias). `resolution` is null or a list of reasons
    // (low_resolution_artwork, small_original); `->` gives the JSON null as a jsonb value, so the type is what tells
    private const string MARK_RESOLUTION = "(jsonb_typeof(%s.result -> 'resolution') = 'array' AND %s.result -> 'resolution' <> '[]'::jsonb)";

    private const string MARK_NOT_THE_PICTURE = "(%s.result -> 'notThePicture' = 'true'::jsonb)";

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function count(ImprovementFilter $filter): int
    {
        [$from, $where, $parameters] = $this->filtered($filter, withBrand: true);

        $count = $this->database->fetchOne("SELECT COUNT(*) {$from} WHERE {$where}", $parameters);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<PuzzleNeedingImprovement>
     */
    public function page(ImprovementFilter $filter, int $limit, int $offset): array
    {
        [$from, $where, $parameters] = $this->filtered($filter, withBrand: true);
        $analysis = $this->analysisOf('page.puzzle_id', 'page.puzzle_image', 'page_analysis');
        $imageNeedsImprovement = sprintf('COALESCE(' . self::MARK_RESOLUTION . ', false)', 'page_analysis', 'page_analysis');
        $wrongPicture = $filter->viewerModerates
            ? sprintf('COALESCE(' . self::MARK_NOT_THE_PICTURE . ', false)', 'page_analysis')
            : 'false';

        // The page is cut first and only its rows get the brand, the badges and the pending-proposal checks. Sorted by
        // the solved count and the id only (paged sorts end on the id) - adding the name made the sort of ~17 000 rows
        // three times slower (45 ms vs 13 ms warm on a production-sized copy, 2026-10-21)
        $query = <<<SQL
SELECT
    page.puzzle_id,
    page.puzzle_name,
    page.puzzle_image,
    page.pieces_count,
    manufacturer.name AS manufacturer_name,
    page.solved_times_count,
    page.ean_missing,
    {$imageNeedsImprovement} AS image_needs_improvement,
    {$wrongPicture} AS wrong_picture,
    EXISTS (
        SELECT 1 FROM puzzle_change_request
        WHERE puzzle_change_request.puzzle_id = page.puzzle_id
            AND puzzle_change_request.status = 'pending'
            AND puzzle_change_request.proposed_ean IS NOT NULL
            AND puzzle_change_request.proposed_ean <> ''
    ) AS ean_proposed,
    EXISTS (
        SELECT 1 FROM puzzle_change_request
        WHERE puzzle_change_request.puzzle_id = page.puzzle_id
            AND puzzle_change_request.status = 'pending'
            AND puzzle_change_request.proposed_image IS NOT NULL
    ) AS image_proposed
FROM (
    SELECT
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        puzzle.image AS puzzle_image,
        puzzle.pieces_count,
        puzzle.manufacturer_id,
        COALESCE(puzzle_statistics.solved_times_count, 0) AS solved_times_count,
        (puzzle.ean IS NULL OR puzzle.ean = '') AS ean_missing
    {$from}
    LEFT JOIN puzzle_statistics ON puzzle_statistics.puzzle_id = puzzle.id
    WHERE {$where}
    ORDER BY solved_times_count DESC, puzzle.id
    LIMIT :limit OFFSET :offset
) page
LEFT JOIN manufacturer ON manufacturer.id = page.manufacturer_id
{$analysis}
ORDER BY page.solved_times_count DESC, page.puzzle_id
SQL;

        /** @var list<array{puzzle_id: string, puzzle_name: string, puzzle_image: null|string, pieces_count: int|string, manufacturer_name: null|string, solved_times_count: int|string, ean_missing: bool, image_needs_improvement: bool, wrong_picture: bool, ean_proposed: bool, image_proposed: bool}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $parameters + [
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return array_map(static fn (array $row): PuzzleNeedingImprovement => new PuzzleNeedingImprovement(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleImage: $row['puzzle_image'],
            piecesCount: (int) $row['pieces_count'],
            manufacturerName: $row['manufacturer_name'],
            solvedTimesCount: (int) $row['solved_times_count'],
            eanMissing: $row['ean_missing'],
            eanProposed: $row['ean_proposed'],
            imageNeedsImprovement: $row['image_needs_improvement'],
            wrongPicture: $row['wrong_picture'],
            imageProposed: $row['image_proposed'],
        ), $rows);
    }

    /**
     * The brands with at least BRAND_FILTER_MIN_PUZZLES puzzles the filter lists (and the selected one), the most such
     * puzzles first - the filter's choices. Every brand would be 1 600+ options, mostly with one puzzle. Within the
     * player's own collections every brand counts - a collection holds few puzzles of each
     *
     * @return list<BrandNeedingImprovement>
     */
    public function brands(ImprovementFilter $filter): array
    {
        [$from, $where, $parameters] = $this->filtered($filter, withBrand: false);

        $query = <<<SQL
SELECT manufacturer.id AS manufacturer_id, manufacturer.name AS manufacturer_name, manufacturer.logo AS manufacturer_logo, COUNT(*) AS puzzles_count
{$from}
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE {$where}
GROUP BY manufacturer.id, manufacturer.name, manufacturer.logo
HAVING COUNT(*) >= :minPuzzles OR manufacturer.id = CAST(:selectedManufacturerId AS uuid)
ORDER BY puzzles_count DESC, manufacturer.name
SQL;

        /** @var list<array{manufacturer_id: string, manufacturer_name: string, manufacturer_logo: null|string, puzzles_count: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $parameters + [
            'minPuzzles' => $filter->collectionPlayerId === null ? self::BRAND_FILTER_MIN_PUZZLES : 1,
            'selectedManufacturerId' => $filter->manufacturerId,
        ]);

        return array_map(static fn (array $row): BrandNeedingImprovement => new BrandNeedingImprovement(
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            puzzlesCount: (int) $row['puzzles_count'],
            manufacturerLogo: $row['manufacturer_logo'],
        ), $rows);
    }

    /**
     * What one puzzle's page tells a signed-in player could be improved - two primary-key lookups in one statement.
     * Null when the puzzle is not on the list at all (unapproved, hidden).
     */
    public function hintForPuzzle(string $puzzleId, bool $viewerModerates): null|PuzzleImprovementHint
    {
        $visible = self::VISIBLE;
        $eanMissing = self::EAN_MISSING;
        $analysis = $this->analysisOf('puzzle.id', 'puzzle.image', 'crop_trust');
        $imageNeedsImprovement = sprintf('COALESCE(' . self::MARK_RESOLUTION . ', false)', 'crop_trust', 'crop_trust');
        $wrongPicture = $viewerModerates
            ? sprintf('COALESCE(' . self::MARK_NOT_THE_PICTURE . ', false)', 'crop_trust')
            : 'false';

        $query = <<<SQL
SELECT
    {$eanMissing} AS ean_missing,
    {$imageNeedsImprovement} AS image_needs_improvement,
    {$wrongPicture} AS wrong_picture
FROM puzzle
{$analysis}
WHERE puzzle.id = :puzzleId AND {$visible}
SQL;

        /** @var false|array{ean_missing: bool, image_needs_improvement: bool, wrong_picture: bool} $row */
        $row = $this->database->fetchAssociative($query, [
            'puzzleId' => $puzzleId,
            'now' => $this->now(),
        ]);

        if ($row === false) {
            return null;
        }

        return new PuzzleImprovementHint(
            eanMissing: $row['ean_missing'],
            imageNeedsImprovement: $row['image_needs_improvement'],
            wrongPicture: $row['wrong_picture'],
        );
    }

    /**
     * The FROM clause (puzzle, plus the marking analyses when the need asks about the image), the WHERE condition and
     * their parameters.
     *
     * @return array{string, string, array<string, null|string>}
     */
    private function filtered(ImprovementFilter $filter, bool $withBrand): array
    {
        $parameters = [
            'now' => $this->now(),
        ];
        $conditions = [self::VISIBLE];

        $imageNeedsImprovement = $this->currentMark('resolution_marked');
        $wrongPicture = $this->currentMark('not_the_picture');
        $imageNeed = $filter->viewerModerates
            ? "({$imageNeedsImprovement} OR {$wrongPicture})"
            : $imageNeedsImprovement;

        $conditions[] = match ($filter->need) {
            ImprovementNeed::All => '(' . self::EAN_MISSING . " OR {$imageNeed})",
            ImprovementNeed::Ean => self::EAN_MISSING,
            ImprovementNeed::Image => $imageNeed,
            // Never for other viewers - ImprovementNeed::fromQuery() does not give it to them, this makes sure
            ImprovementNeed::WrongPicture => $filter->viewerModerates ? $wrongPicture : 'false',
        };

        if ($withBrand && $filter->manufacturerId !== null) {
            $conditions[] = 'puzzle.manufacturer_id = :manufacturerId';
            $parameters['manufacturerId'] = $filter->manufacturerId;
        }

        if ($filter->collectionPlayerId !== null) {
            // Any of the player's collections - the system one (collection_id NULL) and their own
            $conditions[] = 'EXISTS (SELECT 1 FROM collection_item WHERE collection_item.player_id = :collectionPlayerId AND collection_item.puzzle_id = puzzle.id)';
            $parameters['collectionPlayerId'] = $filter->collectionPlayerId;
        }

        return [
            $filter->need === ImprovementNeed::Ean ? 'FROM puzzle' : 'FROM puzzle ' . $this->markingAnalyses(),
            implode("\n    AND ", $conditions),
            $parameters,
        ];
    }

    /**
     * The crop_trust analyses that mark something, joined by the puzzle only (one row per puzzle and analyzer). The
     * subquery's WHERE is word for word the predicate a partial index would need - keep the analyzer a literal, a bound
     * parameter cannot prove it.
     */
    private function markingAnalyses(): string
    {
        $analyzer = ImageAnalyzer::CropTrust->value;
        $resolution = sprintf(self::MARK_RESOLUTION, 'puzzle_image_analysis', 'puzzle_image_analysis');
        $notThePicture = sprintf(self::MARK_NOT_THE_PICTURE, 'puzzle_image_analysis');

        return <<<SQL
LEFT JOIN (
    SELECT puzzle_id, image_fingerprint, {$resolution} AS resolution_marked, {$notThePicture} AS not_the_picture
    FROM puzzle_image_analysis
    WHERE analyzer = '{$analyzer}' AND ({$resolution} OR {$notThePicture})
) marking ON marking.puzzle_id = puzzle.id
SQL;
    }

    /**
     * A marking analysis that describes the picture the puzzle has now. The CASE evaluates the fingerprint for the
     * marked puzzles only - as a join condition the planner made it a hash key and computed it for every puzzle
     */
    private function currentMark(string $mark): string
    {
        $fingerprint = PuzzleImageFingerprint::sql('puzzle.image');

        return "(CASE WHEN marking.puzzle_id IS NULL THEN false ELSE marking.{$mark} AND marking.image_fingerprint = {$fingerprint} END)";
    }

    /**
     * One puzzle's crop_trust analysis of its current picture - for a few rows (index on analyzer + puzzle)
     */
    private function analysisOf(string $puzzleIdColumn, string $imageColumn, string $alias): string
    {
        $analyzer = ImageAnalyzer::CropTrust->value;
        $fingerprint = PuzzleImageFingerprint::sql($imageColumn);

        return "LEFT JOIN puzzle_image_analysis {$alias} ON {$alias}.analyzer = '{$analyzer}' AND {$alias}.puzzle_id = {$puzzleIdColumn} AND {$alias}.image_fingerprint = {$fingerprint}";
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
