<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PuzzleTagCorrectionResult;
use SpeedPuzzling\Web\Results\PuzzleTagResult;
use SpeedPuzzling\Web\Value\PuzzleTagCorrectionVerdict;
use SpeedPuzzling\Web\Value\PuzzleTagGroup;
use SpeedPuzzling\Web\Value\PuzzleTagSource;
use SpeedPuzzling\Web\Value\PuzzleTaxonomy;

/**
 * The stored tags of puzzles' pictures (docs/features/insights/tags.md) - for Insights and the internal API. A puzzle
 * whose picture is hidden now (hide_until / hide_image_until ahead) has none here, whatever is stored for it (its rows
 * go with the next writer run).
 */
readonly final class GetPuzzleTags
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Every tag of the listed puzzles in one statement, keyed by the lower-case puzzle id - optionally only the keys of
     * one registry group (PuzzleTaxonomy).
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, list<PuzzleTagResult>>
     */
    public function forPuzzles(array $puzzleIds, null|PuzzleTagGroup $group = null): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        $parameters = [
            'puzzleIds' => $puzzleIds,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $types = ['puzzleIds' => ArrayParameterType::STRING];
        $groupCondition = '';

        if ($group !== null) {
            $groupCondition = 'AND t.tag_key IN (:groupKeys)';
            $parameters['groupKeys'] = array_map(static fn ($definition): string => $definition->key, PuzzleTaxonomy::inGroup($group));
            $types['groupKeys'] = ArrayParameterType::STRING;
        }

        $rows = $this->database->executeQuery(
            <<<SQL
SELECT t.puzzle_id, t.tag_key, t.score, t.uncertain, t.source, t.analysis_version, t.taxonomy_version, t.updated_at
FROM puzzle_tag t
JOIN puzzle p ON p.id = t.puzzle_id
WHERE t.puzzle_id IN (:puzzleIds)
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    {$groupCondition}
ORDER BY t.puzzle_id, t.tag_key
SQL,
            $parameters,
            $types,
        )->fetchAllAssociative();

        $tags = [];

        foreach ($rows as $row) {
            assert(is_string($row['puzzle_id']));
            assert(is_string($row['tag_key']));
            assert(is_string($row['source']));
            assert(is_string($row['updated_at']));

            $tags[$row['puzzle_id']][] = new PuzzleTagResult(
                puzzleId: $row['puzzle_id'],
                tagKey: $row['tag_key'],
                score: is_numeric($row['score']) ? (float) $row['score'] : null,
                uncertain: $row['uncertain'] === true,
                source: PuzzleTagSource::from($row['source']),
                analysisVersion: is_string($row['analysis_version']) ? $row['analysis_version'] : null,
                taxonomyVersion: is_string($row['taxonomy_version']) ? $row['taxonomy_version'] : null,
                updatedAt: new DateTimeImmutable($row['updated_at']),
            );
        }

        return $tags;
    }

    /**
     * @return list<PuzzleTagResult>
     *
     * @throws PuzzleNotFound unknown puzzle, or its picture hidden now
     */
    public function ofPuzzle(string $puzzleId): array
    {
        $this->assertShown($puzzleId);

        return $this->forPuzzles([$puzzleId])[strtolower($puzzleId)] ?? [];
    }

    /**
     * @return list<PuzzleTagCorrectionResult>
     *
     * @throws PuzzleNotFound unknown puzzle, or its picture hidden now
     */
    public function correctionsOf(string $puzzleId): array
    {
        $this->assertShown($puzzleId);

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT c.tag_key, c.verdict, c.note, c.decided_by_id, c.decided_at, c.taxonomy_version
FROM puzzle_tag_correction c
WHERE c.puzzle_id = :puzzleId
ORDER BY c.tag_key
SQL,
            ['puzzleId' => strtolower($puzzleId)],
        );

        $corrections = [];

        foreach ($rows as $row) {
            assert(is_string($row['tag_key']));
            assert(is_string($row['verdict']));
            assert(is_string($row['decided_at']));
            assert(is_string($row['taxonomy_version']));

            $corrections[] = new PuzzleTagCorrectionResult(
                tagKey: $row['tag_key'],
                verdict: PuzzleTagCorrectionVerdict::from($row['verdict']),
                note: is_string($row['note']) ? $row['note'] : null,
                decidedById: is_string($row['decided_by_id']) ? $row['decided_by_id'] : null,
                decidedAt: new DateTimeImmutable($row['decided_at']),
                taxonomyVersion: $row['taxonomy_version'],
            );
        }

        return $corrections;
    }

    /**
     * @throws PuzzleNotFound
     */
    private function assertShown(string $puzzleId): void
    {
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $shown = $this->database->fetchOne(
            <<<SQL
SELECT 1
FROM puzzle p
WHERE p.id = :puzzleId
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
SQL,
            [
                'puzzleId' => strtolower($puzzleId),
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        if ($shown === false) {
            throw new PuzzleNotFound();
        }
    }
}
