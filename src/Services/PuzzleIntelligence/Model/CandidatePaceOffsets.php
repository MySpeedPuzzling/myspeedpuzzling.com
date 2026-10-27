<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The stored difficulty-adjusted pace offsets (candidate_pace_offset, v7 - AdjustedPace) in memory: per player × piece
 * count the rows in their order, read as of a moment - the latest row strictly before it, like the pace history. What
 * the 15-minute evidence refresh and the reconstruction read the daily fit's offsets through.
 */
final class CandidatePaceOffsets
{
    /** @var array<string, array{solved: list<float>, tracked: list<float>, offset: list<float>}> "player|pieces" => rows ascending */
    private array $pairs = [];

    /**
     * @param null|list<string> $playerIds only these players (null = everybody)
     */
    public static function load(Connection $connection, null|array $playerIds = null): self
    {
        $offsets = new self();

        if ($playerIds === []) {
            return $offsets;
        }

        $rows = $connection->iterateNumeric(
            'SELECT player_id, pieces_count, EXTRACT(EPOCH FROM valid_from), EXTRACT(EPOCH FROM valid_from_tracked_at), pace_offset
            FROM candidate_pace_offset
            ' . ($playerIds !== null ? 'WHERE player_id IN (:players)' : '') . '
            ORDER BY player_id, pieces_count, valid_from, valid_from_tracked_at',
            $playerIds !== null ? ['players' => $playerIds] : [],
            $playerIds !== null ? ['players' => ArrayParameterType::STRING] : [],
        );

        foreach ($rows as $row) {
            /** @var array{0: string, 1: int|string, 2: float|int|string, 3: float|int|string, 4: float|string} $row */
            $offsets->add($row[0], (int) $row[1], (float) $row[2], (float) $row[3], (float) $row[4]);
        }

        return $offsets;
    }

    /**
     * A row - in the order of the moments.
     */
    public function add(string $playerId, int $piecesCount, float $solved, float $tracked, float $offset): void
    {
        $key = $playerId . '|' . $piecesCount;
        $this->pairs[$key] ??= ['solved' => [], 'tracked' => [], 'offset' => []];
        $this->pairs[$key]['solved'][] = $solved;
        $this->pairs[$key]['tracked'][] = $tracked;
        $this->pairs[$key]['offset'][] = $offset;
    }

    /**
     * The pair's offset at a moment: its latest row strictly before it (or at it, inclusive), null without one.
     */
    public function at(string $playerId, int $piecesCount, float $solved, float $tracked, bool $inclusive = false): null|float
    {
        $pair = $this->pairs[$playerId . '|' . $piecesCount] ?? null;

        if ($pair === null) {
            return null;
        }

        $low = 0;
        $high = count($pair['solved']);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $order = $pair['solved'][$middle] <=> $solved ?: $pair['tracked'][$middle] <=> $tracked;

            if ($order < 0 || ($inclusive && $order === 0)) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low > 0 ? $pair['offset'][$low - 1] : null;
    }

    /**
     * The mean offset of the player's pairs that have a row before a moment (an index from a pace of their other sizes
     * or their first pace - CandidateEngine) - 0 without one.
     *
     * @param list<int> $piecesCounts the player's piece counts
     */
    public function meanAt(string $playerId, array $piecesCounts, float $solved, float $tracked): float
    {
        $sum = 0.0;
        $count = 0;

        foreach ($piecesCounts as $piecesCount) {
            $offset = $this->at($playerId, $piecesCount, $solved, $tracked, inclusive: true);

            if ($offset !== null) {
                $sum += $offset;
                $count++;
            }
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    public function isEmpty(): bool
    {
        return $this->pairs === [];
    }
}
