<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A player's counted solo times per piece count and brand (GetPlayerChartData::soloCounts()) - the profile chart's two
 * filters read both from one statement: the piece counts (of one brand, or all) and the brands of one piece count.
 *
 * Both lists put the most solved first. A tie used to be left to the database (a hash aggregate's order, which also
 * decided the chart's default piece count); it is explicit now and matches what PostgreSQL returned in the common case
 * (checked on a copy of production, 2026-11-10): equal piece counts smallest first (338 of the 343 players with a tie
 * for the top keep their default piece count), equal brands by brand id, oldest brand first (3,875 of the 6,259 lists
 * with a tie unchanged; by name: 1,559).
 *
 * @phpstan-type SoloCountRow array{pieces_count: int|string, brand_id: string, brand_name: string, solved_count: int|string}
 */
readonly final class PlayerSoloCounts
{
    /**
     * @param list<array{pieces: int, brandId: string, brandName: string, count: int}> $rows
     */
    private function __construct(
        private array $rows,
    ) {
    }

    /**
     * @param list<SoloCountRow> $rows
     */
    public static function fromDatabaseRows(array $rows): self
    {
        return new self(array_map(static fn (array $row): array => [
            'pieces' => (int) $row['pieces_count'],
            'brandId' => $row['brand_id'],
            'brandName' => $row['brand_name'],
            'count' => (int) $row['solved_count'],
        ], $rows));
    }

    /**
     * The piece counts with a counted solo time (of the brand, when given), the most solved first, a tie smallest first
     *
     * @return list<int>
     */
    public function piecesCounts(null|string $brandId): array
    {
        $counts = [];

        foreach ($this->rows as $row) {
            if ($brandId !== null && $row['brandId'] !== $brandId) {
                continue;
            }

            $counts[$row['pieces']] = ($counts[$row['pieces']] ?? 0) + $row['count'];
        }

        uksort($counts, static fn (int $a, int $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return array_keys($counts);
    }

    /**
     * The brands of one piece count with their counted solo times ("Brand (n)"), the most solved first, a tie by brand id
     *
     * @return array<string, string> brand id => label
     */
    public function brands(int $piecesCount): array
    {
        $rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['pieces'] === $piecesCount));

        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['brandId']] <=> [$a['count'], $b['brandId']]);

        $brands = [];

        foreach ($rows as $row) {
            $brands[$row['brandId']] = sprintf('%s (%d)', $row['brandName'], $row['count']);
        }

        return $brands;
    }
}
