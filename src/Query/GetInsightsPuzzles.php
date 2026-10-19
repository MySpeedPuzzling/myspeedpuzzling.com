<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\InsightsPuzzle;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * The hydrate statement of the Insights page: display data of the puzzles a built page shows
 * (InsightsReport::puzzleIdsShown(), at most InsightsReport::MAX_SHOWN_PUZZLES), one statement whatever the player's
 * size. A secret puzzle is left out entirely (the page never has one - GetInsightsSoloResults leaves them out too).
 */
readonly final class GetInsightsPuzzles
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, InsightsPuzzle> keyed by the lower-case puzzle id, in the order of $puzzleIds
     */
    public function byIds(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_filter(
            array_map(strtolower(...), $puzzleIds),
            static fn (string $puzzleId): bool => Uuid::isValid($puzzleId),
        )));

        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    p.id AS puzzle_id,
    p.name,
    p.alternative_names,
    m.name AS manufacturer_name,
    p.pieces_count,
    p.image,
    p.image_ratio
FROM puzzle p
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE p.id IN (:puzzleIds)
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
SQL;

        /**
         * @var list<array{
         *     puzzle_id: string,
         *     name: string,
         *     alternative_names: null|string,
         *     manufacturer_name: null|string,
         *     pieces_count: int|string,
         *     image: null|string,
         *     image_ratio: null|float|string,
         * }> $rows
         */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['puzzleIds' => $puzzleIds, 'now' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['puzzleIds' => ArrayParameterType::STRING],
        );

        $byId = [];

        foreach ($rows as $row) {
            $byId[strtolower($row['puzzle_id'])] = new InsightsPuzzle(
                puzzleId: $row['puzzle_id'],
                name: $row['name'],
                alternativeNames: PuzzleNames::fromJson($row['alternative_names']),
                manufacturerName: $row['manufacturer_name'],
                piecesCount: (int) $row['pieces_count'],
                image: $row['image'],
                imageRatio: $row['image_ratio'] !== null ? (float) $row['image_ratio'] : null,
            );
        }

        $ordered = [];

        foreach ($puzzleIds as $puzzleId) {
            if (isset($byId[$puzzleId])) {
                $ordered[$puzzleId] = $byId[$puzzleId];
            }
        }

        return $ordered;
    }
}
