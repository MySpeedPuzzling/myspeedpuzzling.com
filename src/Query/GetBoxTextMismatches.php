<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\BoxTextMismatchRow;
use SpeedPuzzling\Web\Value\BoxTextCatalogue;
use SpeedPuzzling\Web\Value\BoxTextField;
use SpeedPuzzling\Web\Value\BoxTextMismatchSort;

/**
 * The moderators' box-text mismatch list (docs/features/puzzle-approvals.md, "Box-text mismatches"): the detected
 * mismatches nobody dismissed, with the record as it is now. A puzzle whose picture is hidden now (a secret competition
 * puzzle, a placeholder) is listed for admins only - moderators never see one.
 */
readonly final class GetBoxTextMismatches
{
    public const int PAGE_SIZE = 30;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function count(null|BoxTextField $field, bool $includeHidden): int
    {
        [$conditions, $parameters] = $this->conditions($field, $includeHidden);

        $count = $this->database->fetchOne(
            <<<SQL
SELECT count(*)
FROM box_text_mismatch btm
JOIN puzzle p ON p.id = btm.puzzle_id
WHERE {$conditions}
SQL,
            $parameters,
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<BoxTextMismatchRow>
     */
    public function page(null|BoxTextField $field, BoxTextMismatchSort $sort, bool $includeHidden, int $page): array
    {
        [$conditions, $parameters] = $this->conditions($field, $includeHidden);
        // The select tells admins which puzzles are hidden now
        $parameters['now'] = $this->clock->now()->format('Y-m-d H:i:s');
        $parameters['limit'] = self::PAGE_SIZE;
        $parameters['offset'] = (max(1, $page) - 1) * self::PAGE_SIZE;

        // Ties end on the puzzle id and the field, so a page never repeats or skips a line
        $order = match ($sort) {
            BoxTextMismatchSort::MostSolved => 'COALESCE(ps.solved_times_count, 0) DESC, btm.analysis_received_at DESC, btm.puzzle_id, btm.field',
            BoxTextMismatchSort::Newest => 'btm.analysis_received_at DESC, btm.puzzle_id, btm.field',
        };

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    btm.puzzle_id,
    btm.field,
    btm.value_key,
    btm.box_values,
    btm.evidence,
    btm.analysis_received_at,
    btm.detected_at,
    p.name AS puzzle_name,
    p.image AS puzzle_image,
    p.pieces_count,
    p.ean,
    p.identification_number,
    m.name AS manufacturer_name,
    COALESCE(ps.solved_times_count, 0) AS solved_count,
    NOT ({$this->sqlShown()}) AS hidden
FROM box_text_mismatch btm
JOIN puzzle p ON p.id = btm.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
WHERE {$conditions}
ORDER BY {$order}
LIMIT :limit OFFSET :offset
SQL,
            $parameters,
        );

        $mismatches = [];

        foreach ($rows as $row) {
            assert(is_string($row['puzzle_id']));
            assert(is_string($row['field']));
            assert(is_string($row['value_key']));
            assert(is_string($row['box_values']));
            assert(is_string($row['evidence']));
            assert(is_string($row['analysis_received_at']));
            assert(is_string($row['detected_at']));
            assert(is_string($row['puzzle_name']));
            assert(is_int($row['pieces_count']));

            $mismatches[] = new BoxTextMismatchRow(
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleImage: is_string($row['puzzle_image']) ? $row['puzzle_image'] : null,
                field: BoxTextField::from($row['field']),
                valueKey: $row['value_key'],
                boxValues: self::strings($row['box_values']),
                evidence: self::strings($row['evidence']),
                catalogue: new BoxTextCatalogue(
                    piecesCount: $row['pieces_count'],
                    ean: is_string($row['ean']) ? $row['ean'] : null,
                    brandCodes: is_string($row['identification_number']) ? $row['identification_number'] : null,
                    brandName: is_string($row['manufacturer_name']) ? $row['manufacturer_name'] : null,
                ),
                solvedCount: is_numeric($row['solved_count']) ? (int) $row['solved_count'] : 0,
                analysisReceivedAt: new DateTimeImmutable($row['analysis_received_at']),
                detectedAt: new DateTimeImmutable($row['detected_at']),
                hidden: $row['hidden'] === true,
            );
        }

        return $mismatches;
    }

    /**
     * @return array{string, array<string, int|string>}
     */
    private function conditions(null|BoxTextField $field, bool $includeHidden): array
    {
        $conditions = [
            // Dismissed once = never again while the box reads the same
            'NOT EXISTS (SELECT 1 FROM box_text_mismatch_decision d WHERE d.puzzle_id = btm.puzzle_id AND d.field = btm.field AND d.value_key = btm.value_key)',
        ];
        $parameters = [];

        if ($includeHidden === false) {
            $conditions[] = $this->sqlShown();
            $parameters['now'] = $this->clock->now()->format('Y-m-d H:i:s');
        }

        if ($field !== null) {
            $conditions[] = 'btm.field = :field';
            $parameters['field'] = $field->value;
        }

        return [implode(' AND ', $conditions), $parameters];
    }

    private function sqlShown(): string
    {
        return '((p.hide_until IS NULL OR p.hide_until <= CAST(:now AS timestamp)) AND (p.hide_image_until IS NULL OR p.hide_image_until <= CAST(:now AS timestamp)))';
    }

    /**
     * @return list<string>
     */
    private static function strings(string $json): array
    {
        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
