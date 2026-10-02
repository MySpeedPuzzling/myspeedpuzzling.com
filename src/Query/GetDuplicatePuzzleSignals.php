<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalListItem;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalPuzzle;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalReason;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalStatus;

/**
 * The catalogue signals on /admin/duplicate-results (docs/features/duplicate-results.md, Layer 4) - stored and scored
 * by the detection cron, read here as they are. Strong and weak ones are listed apart (weak only on request), highest
 * score first, then most matching results.
 */
readonly final class GetDuplicatePuzzleSignals
{
    public const int PER_PAGE = 30;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, int> status => signals, every status present
     */
    public function countsByStatus(): array
    {
        $counts = [];

        foreach (DuplicatePuzzleSignalStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        /** @var list<array{status: string, signals: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative('SELECT status, COUNT(*) AS signals FROM duplicate_puzzle_signal GROUP BY status');

        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['signals'];
        }

        return $counts;
    }

    /**
     * @return array{strong: int, weak: int}
     */
    public function openCountsByStrength(): array
    {
        /** @var array{strong: int|string, weak: int|string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE NOT weak) AS strong, COUNT(*) FILTER (WHERE weak) AS weak FROM duplicate_puzzle_signal WHERE status = :open',
            ['open' => DuplicatePuzzleSignalStatus::Open->value],
        );

        return ['strong' => (int) $row['strong'], 'weak' => (int) $row['weak']];
    }

    /**
     * @return list<DuplicatePuzzleSignalListItem>
     */
    public function open(int $page, bool $weak = false): array
    {
        $query = <<<SQL
SELECT
    puzzle_signal.id AS signal_id,
    puzzle_signal.matching_results,
    puzzle_signal.matching_people,
    puzzle_signal.example_player_id,
    example_player.name AS example_player_name,
    example_player.code AS example_player_code,
    puzzle_signal.example_seconds,
    puzzle_signal.example_day,
    puzzle_signal.detected_at,
    puzzle_signal.score,
    puzzle_signal.reasons,
    puzzle_signal.name_similarity,
    puzzle_signal.weak,
    {$this->puzzleColumns('a')},
    {$this->puzzleColumns('b')}
FROM duplicate_puzzle_signal puzzle_signal
INNER JOIN puzzle puzzle_a ON puzzle_a.id = puzzle_signal.puzzle_a_id
INNER JOIN manufacturer manufacturer_a ON manufacturer_a.id = puzzle_a.manufacturer_id
LEFT JOIN puzzle_statistics statistics_a ON statistics_a.puzzle_id = puzzle_a.id
INNER JOIN puzzle puzzle_b ON puzzle_b.id = puzzle_signal.puzzle_b_id
INNER JOIN manufacturer manufacturer_b ON manufacturer_b.id = puzzle_b.manufacturer_id
LEFT JOIN puzzle_statistics statistics_b ON statistics_b.puzzle_id = puzzle_b.id
LEFT JOIN player example_player ON example_player.id = puzzle_signal.example_player_id
WHERE puzzle_signal.status = :open
    AND puzzle_signal.weak = :weak
ORDER BY puzzle_signal.score DESC, puzzle_signal.matching_results DESC, puzzle_signal.matching_people DESC, puzzle_signal.detected_at, puzzle_signal.id
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array<string, null|bool|float|int|string>> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'open' => DuplicatePuzzleSignalStatus::Open->value,
            'weak' => $weak,
            'limit' => self::PER_PAGE,
            'offset' => (max(1, $page) - 1) * self::PER_PAGE,
        ], [
            'weak' => ParameterType::BOOLEAN,
        ]);

        return array_map(fn (array $row): DuplicatePuzzleSignalListItem => new DuplicatePuzzleSignalListItem(
            signalId: (string) $row['signal_id'],
            puzzleA: $this->puzzle($row, 'a'),
            puzzleB: $this->puzzle($row, 'b'),
            matchingResults: (int) $row['matching_results'],
            matchingPeople: (int) $row['matching_people'],
            examplePlayerId: (string) $row['example_player_id'],
            examplePlayerName: is_string($row['example_player_name']) ? $row['example_player_name'] : null,
            examplePlayerCode: is_string($row['example_player_code']) ? $row['example_player_code'] : null,
            exampleSeconds: (int) $row['example_seconds'],
            exampleDay: new DateTimeImmutable((string) $row['example_day']),
            detectedAt: new DateTimeImmutable((string) $row['detected_at']),
            score: (int) $row['score'],
            reasons: $this->reasons((string) $row['reasons']),
            nameSimilarity: (float) $row['name_similarity'],
            weak: (bool) $row['weak'],
        ), $rows);
    }

    /**
     * @return list<DuplicatePuzzleSignalReason>
     */
    private function reasons(string $json): array
    {
        /** @var list<string> $values */
        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter(array_map(
            static fn (string $value): null|DuplicatePuzzleSignalReason => DuplicatePuzzleSignalReason::tryFrom($value),
            $values,
        )));
    }

    private function puzzleColumns(string $side): string
    {
        return <<<SQL
puzzle_{$side}.id AS {$side}_id,
    puzzle_{$side}.name AS {$side}_name,
    puzzle_{$side}.image AS {$side}_image,
    manufacturer_{$side}.name AS {$side}_manufacturer_name,
    puzzle_{$side}.pieces_count AS {$side}_pieces_count,
    puzzle_{$side}.ean AS {$side}_ean,
    puzzle_{$side}.identification_number AS {$side}_identification_number,
    puzzle_{$side}.approved AS {$side}_approved,
    COALESCE(statistics_{$side}.solved_times_count, 0) AS {$side}_results
SQL;
    }

    /**
     * @param array<string, null|bool|float|int|string> $row
     */
    private function puzzle(array $row, string $side): DuplicatePuzzleSignalPuzzle
    {
        return new DuplicatePuzzleSignalPuzzle(
            id: (string) $row["{$side}_id"],
            name: (string) $row["{$side}_name"],
            image: is_string($row["{$side}_image"]) ? $row["{$side}_image"] : null,
            manufacturerName: (string) $row["{$side}_manufacturer_name"],
            piecesCount: (int) $row["{$side}_pieces_count"],
            ean: is_string($row["{$side}_ean"]) ? $row["{$side}_ean"] : null,
            identificationNumber: is_string($row["{$side}_identification_number"]) ? $row["{$side}_identification_number"] : null,
            approved: (bool) $row["{$side}_approved"],
            results: (int) $row["{$side}_results"],
        );
    }
}
