<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleTags;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PuzzleTagWriteOutcome;
use SpeedPuzzling\Web\Value\ImageAnalyzer;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\PuzzleTagCorrectionVerdict;
use SpeedPuzzling\Web\Value\PuzzleTagSource;
use SpeedPuzzling\Web\Value\PuzzleTaxonomy;

/**
 * Writes puzzle_tag from the `tags` analyses and the corrections (docs/features/insights/tags.md) - set-based SQL on
 * purpose: tens of thousands of puzzles with ~15 tags each, a bulk derivation no handler should load row by row.
 *
 * A puzzle's tags = the keys `assigned` by the `tags` analysis of its **current** picture (fingerprint), minus the keys
 * a correction removes, plus the keys a correction adds. A secret puzzle (hide_until / hide_image_until ahead) gets
 * none - its rows go with the run after it became secret, so readers check the two columns too.
 *
 * Watermark per puzzle (puzzle_tag_state): the signature of the analysis (version, taxonomy, assigned tags) and of the
 * corrections the rows were last written from. A run rewrites only the puzzles whose current signatures differ - a new
 * push, a new picture (its analysis no longer counts), new corrections, a puzzle turning secret or revealed - so a run
 * with nothing new writes nothing, and a second run in a row never changes anything (idempotent).
 */
readonly final class PuzzleTagWriter
{
    private const int CHUNK = 1000;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|list<string> $puzzleIds only these puzzles, rewritten whatever their watermark says (after a
     *                                     correction); null = every puzzle whose input changed since its last write
     * @param bool $full every puzzle with an analysis, corrections or tags - after a change of the rules here
     */
    public function refresh(null|array $puzzleIds = null, bool $full = false): PuzzleTagWriteOutcome
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        if ($puzzleIds !== null) {
            $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));
        } else {
            $puzzleIds = $this->puzzlesToRewrite($now, $full);
        }

        $outcome = new PuzzleTagWriteOutcome(0, 0, 0);

        foreach (array_chunk($puzzleIds, self::CHUNK) as $chunk) {
            $outcome = $outcome->plus($this->rewrite($chunk, $now));
        }

        return $outcome;
    }

    /**
     * @return list<string>
     */
    private function puzzlesToRewrite(string $now, bool $full): array
    {
        $signatures = self::sqlSignatures('p');
        $changed = $full ? '' : 'AND (current_input.analysis_signature IS DISTINCT FROM s.analysis_signature OR current_input.corrections_signature IS DISTINCT FROM s.corrections_signature)';

        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            <<<SQL
WITH candidate AS (
    SELECT puzzle_id FROM puzzle_image_analysis WHERE analyzer = :tagsAnalyzer
    UNION
    SELECT puzzle_id FROM puzzle_tag_correction
    UNION
    SELECT puzzle_id FROM puzzle_tag_state
),
current_input AS (
    SELECT p.id AS puzzle_id, {$signatures}
    FROM candidate
    JOIN puzzle p ON p.id = candidate.puzzle_id
)
SELECT current_input.puzzle_id
FROM current_input
LEFT JOIN puzzle_tag_state s ON s.puzzle_id = current_input.puzzle_id
WHERE (s.puzzle_id IS NOT NULL OR current_input.analysis_signature IS NOT NULL OR current_input.corrections_signature IS NOT NULL)
    {$changed}
ORDER BY current_input.puzzle_id
SQL,
            [
                'tagsAnalyzer' => ImageAnalyzer::Tags->value,
                'now' => $now,
            ],
        );

        return $ids;
    }

    /**
     * One statement per chunk: the tag rows (removed / inserted / updated only where something differs) and the
     * watermark together, so both always describe the same input.
     *
     * @param list<string> $puzzleIds
     */
    private function rewrite(array $puzzleIds, string $now): PuzzleTagWriteOutcome
    {
        $signatures = self::sqlSignatures('p');
        $visible = self::sqlVisible('p');
        $fingerprint = PuzzleImageFingerprint::sql('t.image');
        $wellFormed = PuzzleTaxonomy::sqlIsWellFormedKey('e.key');

        $row = $this->database->fetchAssociative(
            <<<SQL
WITH chunk AS (
    SELECT p.id AS puzzle_id, p.image, {$visible} AS visible, {$signatures}
    FROM puzzle p
    WHERE p.id IN (:puzzleIds)
),
target AS (
    SELECT * FROM chunk WHERE visible
),
analysis AS (
    SELECT a.puzzle_id, a.analyzer_version, a.result
    FROM target t
    JOIN puzzle_image_analysis a ON a.puzzle_id = t.puzzle_id AND a.analyzer = :tagsAnalyzer AND a.image_fingerprint = {$fingerprint}
),
assigned AS (
    SELECT
        a.puzzle_id,
        e.key AS tag_key,
        CASE WHEN jsonb_typeof(e.value -> 's') = 'number' THEN (e.value ->> 's')::double precision END AS score,
        COALESCE(e.value -> 'uncertain' = 'true'::jsonb, false) AS uncertain,
        a.analyzer_version,
        left(a.result ->> 'taxonomy', 16) AS taxonomy_version
    FROM analysis a
    -- An empty map comes back from PHP as a list (`[]`) - only an object holds tags
    CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(a.result -> 'assigned') = 'object' THEN a.result -> 'assigned' ELSE '{}'::jsonb END) e
    WHERE {$wellFormed}
),
correction AS (
    SELECT c.puzzle_id, c.tag_key, c.verdict, c.taxonomy_version
    FROM target t
    JOIN puzzle_tag_correction c ON c.puzzle_id = t.puzzle_id
),
desired AS (
    SELECT s.puzzle_id, s.tag_key, s.score, s.uncertain, CAST(:analysisSource AS varchar) AS source, s.analyzer_version AS analysis_version, s.taxonomy_version
    FROM assigned s
    WHERE NOT EXISTS (SELECT 1 FROM correction c WHERE c.puzzle_id = s.puzzle_id AND c.tag_key = s.tag_key)
    UNION ALL
    SELECT c.puzzle_id, c.tag_key, s.score, false, CAST(:correctionSource AS varchar), s.analyzer_version, COALESCE(s.taxonomy_version, c.taxonomy_version)
    FROM correction c
    LEFT JOIN assigned s ON s.puzzle_id = c.puzzle_id AND s.tag_key = c.tag_key
    WHERE c.verdict = :add
),
removed AS (
    DELETE FROM puzzle_tag pt
    WHERE pt.puzzle_id IN (SELECT puzzle_id FROM chunk)
        AND NOT EXISTS (SELECT 1 FROM desired d WHERE d.puzzle_id = pt.puzzle_id AND d.tag_key = pt.tag_key)
    RETURNING 1
),
written AS (
    INSERT INTO puzzle_tag (puzzle_id, tag_key, score, uncertain, source, analysis_version, taxonomy_version, updated_at)
    SELECT d.puzzle_id, d.tag_key, d.score, d.uncertain, d.source, d.analysis_version, d.taxonomy_version, CAST(:now AS timestamp)
    FROM desired d
    ON CONFLICT (puzzle_id, tag_key) DO UPDATE SET
        score = EXCLUDED.score,
        uncertain = EXCLUDED.uncertain,
        source = EXCLUDED.source,
        analysis_version = EXCLUDED.analysis_version,
        taxonomy_version = EXCLUDED.taxonomy_version,
        updated_at = EXCLUDED.updated_at
    WHERE (puzzle_tag.score, puzzle_tag.uncertain, puzzle_tag.source, puzzle_tag.analysis_version, puzzle_tag.taxonomy_version)
        IS DISTINCT FROM (EXCLUDED.score, EXCLUDED.uncertain, EXCLUDED.source, EXCLUDED.analysis_version, EXCLUDED.taxonomy_version)
    RETURNING 1
),
state_written AS (
    INSERT INTO puzzle_tag_state (puzzle_id, analysis_signature, corrections_signature, written_at)
    SELECT c.puzzle_id, c.analysis_signature, c.corrections_signature, CAST(:now AS timestamp)
    FROM chunk c
    WHERE c.analysis_signature IS NOT NULL OR c.corrections_signature IS NOT NULL
    ON CONFLICT (puzzle_id) DO UPDATE SET
        analysis_signature = EXCLUDED.analysis_signature,
        corrections_signature = EXCLUDED.corrections_signature,
        written_at = EXCLUDED.written_at
    WHERE (puzzle_tag_state.analysis_signature, puzzle_tag_state.corrections_signature)
        IS DISTINCT FROM (EXCLUDED.analysis_signature, EXCLUDED.corrections_signature)
    RETURNING 1
),
state_removed AS (
    DELETE FROM puzzle_tag_state s
    WHERE s.puzzle_id IN (SELECT puzzle_id FROM chunk WHERE analysis_signature IS NULL AND corrections_signature IS NULL)
    RETURNING 1
)
SELECT
    (SELECT count(*) FROM removed) AS removed,
    (SELECT count(*) FROM written) AS written,
    (SELECT count(*) FROM state_written) + (SELECT count(*) FROM state_removed) AS states
SQL,
            [
                'puzzleIds' => $puzzleIds,
                'now' => $now,
                'tagsAnalyzer' => ImageAnalyzer::Tags->value,
                'analysisSource' => PuzzleTagSource::Analysis->value,
                'correctionSource' => PuzzleTagSource::Correction->value,
                'add' => PuzzleTagCorrectionVerdict::Add->value,
            ],
            [
                'puzzleIds' => ArrayParameterType::STRING,
            ],
        );

        assert(is_array($row));

        return new PuzzleTagWriteOutcome(
            puzzles: count($puzzleIds),
            written: is_numeric($row['written']) ? (int) $row['written'] : 0,
            removed: is_numeric($row['removed']) ? (int) $row['removed'] : 0,
        );
    }

    /**
     * analysis_signature, corrections_signature of a puzzle (alias) - both null while it is secret. Bind :now and
     * :tagsAnalyzer.
     */
    private static function sqlSignatures(string $puzzleAlias): string
    {
        $visible = self::sqlVisible($puzzleAlias);
        $fingerprint = PuzzleImageFingerprint::sql($puzzleAlias . '.image');

        return <<<SQL
CASE WHEN {$visible} THEN (
    SELECT md5(sa.analyzer_version || '|' || COALESCE(sa.result ->> 'taxonomy', '') || '|' || COALESCE((sa.result -> 'assigned')::text, ''))
    FROM puzzle_image_analysis sa
    WHERE sa.puzzle_id = {$puzzleAlias}.id AND sa.analyzer = :tagsAnalyzer AND sa.image_fingerprint = {$fingerprint}
) END AS analysis_signature,
CASE WHEN {$visible} THEN (
    SELECT md5(string_agg(sc.tag_key || '=' || sc.verdict, ',' ORDER BY sc.tag_key))
    FROM puzzle_tag_correction sc
    WHERE sc.puzzle_id = {$puzzleAlias}.id
) END AS corrections_signature
SQL;
    }

    private static function sqlVisible(string $puzzleAlias): string
    {
        return "(({$puzzleAlias}.hide_until IS NULL OR {$puzzleAlias}.hide_until <= CAST(:now AS timestamp))"
            . " AND ({$puzzleAlias}.hide_image_until IS NULL OR {$puzzleAlias}.hide_image_until <= CAST(:now AS timestamp)))";
    }
}
