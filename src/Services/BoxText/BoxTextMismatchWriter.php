<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\BoxText;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\DetectedBoxTextMismatch;

/**
 * Replaces the whole box_text_mismatch table with a detection run's result (docs/features/puzzle-approvals.md,
 * "Box-text mismatches") - one set-based statement on purpose: a run covers every analysed puzzle (tens of thousands),
 * a bulk derivation no handler should load row by row. A mismatch still there keeps its row and the moment it was
 * first detected (unless its box values changed); one gone is removed; dismissals live apart and are never touched.
 */
readonly final class BoxTextMismatchWriter
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{mismatch: DetectedBoxTextMismatch, analysisReceivedAt: DateTimeImmutable}> $mismatches
     *
     * @return array{written: int, removed: int}
     */
    public function replaceAll(array $mismatches): array
    {
        $rows = array_map(static fn (array $item): array => [
            'puzzle_id' => $item['mismatch']->puzzleId,
            'field' => $item['mismatch']->field->value,
            'value_key' => $item['mismatch']->valueKey(),
            'box_values' => $item['mismatch']->boxValues,
            'catalogue_value' => $item['mismatch']->catalogueValue !== null ? mb_substr($item['mismatch']->catalogueValue, 0, 255) : null,
            'evidence' => $item['mismatch']->evidence,
            'analysis_received_at' => $item['analysisReceivedAt']->format('Y-m-d H:i:s'),
        ], $mismatches);

        $row = $this->database->fetchAssociative(
            <<<SQL
WITH incoming AS (
    SELECT *
    FROM jsonb_to_recordset(CAST(:rows AS jsonb)) AS x(
        puzzle_id uuid,
        field varchar(16),
        value_key varchar(255),
        box_values jsonb,
        catalogue_value varchar(255),
        evidence jsonb,
        analysis_received_at timestamp
    )
    -- A puzzle deleted (merged) since it was read
    WHERE EXISTS (SELECT 1 FROM puzzle p WHERE p.id = x.puzzle_id)
),
removed AS (
    DELETE FROM box_text_mismatch m
    WHERE NOT EXISTS (SELECT 1 FROM incoming i WHERE i.puzzle_id = m.puzzle_id AND i.field = m.field)
    RETURNING 1
),
written AS (
    INSERT INTO box_text_mismatch (puzzle_id, field, value_key, box_values, catalogue_value, evidence, analysis_received_at, detected_at)
    SELECT i.puzzle_id, i.field, i.value_key, i.box_values, i.catalogue_value, i.evidence, i.analysis_received_at, CAST(:now AS timestamp)
    FROM incoming i
    ON CONFLICT (puzzle_id, field) DO UPDATE SET
        value_key = EXCLUDED.value_key,
        box_values = EXCLUDED.box_values,
        catalogue_value = EXCLUDED.catalogue_value,
        evidence = EXCLUDED.evidence,
        analysis_received_at = EXCLUDED.analysis_received_at,
        detected_at = CASE WHEN box_text_mismatch.value_key = EXCLUDED.value_key THEN box_text_mismatch.detected_at ELSE EXCLUDED.detected_at END
    WHERE (box_text_mismatch.value_key, box_text_mismatch.box_values, box_text_mismatch.catalogue_value, box_text_mismatch.evidence, box_text_mismatch.analysis_received_at)
        IS DISTINCT FROM (EXCLUDED.value_key, EXCLUDED.box_values, EXCLUDED.catalogue_value, EXCLUDED.evidence, EXCLUDED.analysis_received_at)
    RETURNING 1
)
SELECT (SELECT count(*) FROM written) AS written, (SELECT count(*) FROM removed) AS removed
SQL,
            [
                'rows' => json_encode($rows, JSON_THROW_ON_ERROR),
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        assert(is_array($row));

        return [
            'written' => is_numeric($row['written']) ? (int) $row['written'] : 0,
            'removed' => is_numeric($row['removed']) ? (int) $row['removed'] : 0,
        ];
    }
}
