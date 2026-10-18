<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

/**
 * The deletes and inserts one PlayerPaceHistoryWriter::sync() collects before writing them in bulk.
 */
final class PaceHistoryWrites
{
    /** @var list<array{0: string, 1: int, 2: int}> player, piece count, first qualifying_count to delete */
    private array $deletes = [];

    /** @var list<array{0: string, 1: int, 2: array{qualifying_count: int, time_id: string, valid_from: string, valid_from_tracked_at: string, baseline_seconds: int, step: null|float, inputs_checksum: int}}> */
    private array $inserts = [];

    public function delete(string $playerId, int $piecesCount, int $fromQualifyingCount): void
    {
        $this->deletes[] = [$playerId, $piecesCount, $fromQualifyingCount];
    }

    /**
     * @param array{qualifying_count: int, time_id: string, valid_from: string, valid_from_tracked_at: string, baseline_seconds: int, step: null|float, inputs_checksum: int} $row
     */
    public function insert(string $playerId, int $piecesCount, array $row): void
    {
        $this->inserts[] = [$playerId, $piecesCount, $row];
    }

    public function insertCount(): int
    {
        return count($this->inserts);
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public function takeDeletes(): array
    {
        $deletes = $this->deletes;
        $this->deletes = [];

        return $deletes;
    }

    /**
     * @return list<array{0: string, 1: int, 2: array{qualifying_count: int, time_id: string, valid_from: string, valid_from_tracked_at: string, baseline_seconds: int, step: null|float, inputs_checksum: int}}>
     */
    public function takeInserts(): array
    {
        $inserts = $this->inserts;
        $this->inserts = [];

        return $inserts;
    }
}
