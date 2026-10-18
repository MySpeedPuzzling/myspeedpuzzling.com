<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerRatingPortfolio;

/**
 * Writes player_rating_entry while the rating step walks the players: their entries are compared with the stored ones
 * a chunk of players at a time (ChangedRowsSyncRun), so memory stays flat with ~300k entries. Players the run never
 * reached (private now, no solves left) and piece counts no longer rated are deleted at the end. Not a service: one per
 * batch run (RatingInsightsWriter::openEntries()).
 */
final class RatingEntriesSync
{
    private const int PLAYERS_PER_CHUNK = 500;

    /** @var list<string> */
    private array $chunkPlayers = [];

    private null|int $chunkPiecesCount = null;

    /** @var array<int, list<string>> pieces count => players the run scored */
    private array $seen = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ChangedRowsSyncRun $run,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public function add(string $playerId, int $piecesCount, PlayerRatingPortfolio $portfolio): void
    {
        if ($this->chunkPiecesCount !== $piecesCount || count($this->chunkPlayers) >= self::PLAYERS_PER_CHUNK) {
            $this->flush();
            $this->chunkPiecesCount = $piecesCount;
        }

        $this->chunkPlayers[] = $playerId;
        $this->seen[$piecesCount][] = $playerId;

        foreach ($portfolio->entries as $entry) {
            $this->run->add([
                'player_id' => $playerId,
                'pieces_count' => $piecesCount,
                'puzzle_id' => $entry->puzzleId,
                'portfolio' => $entry->portfolio->value,
                'seconds' => $entry->seconds,
                'solved_at' => $entry->solvedAt,
                'percentile' => $entry->percentile,
                'difficulty_weight' => $entry->difficultyWeight,
                'decay' => $entry->decay,
                'points' => $entry->points,
                'in_top' => $entry->inTop,
            ]);
        }
    }

    /**
     * @param null|string $onlyPlayer a run for one player (--player) speaks only for that player's rows
     * @return array{written: int, deleted: int}
     */
    public function finish(null|string $onlyPlayer): array
    {
        $this->flush();
        $result = $this->run->finish($this->now, 'FALSE');

        if ($onlyPlayer === null) {
            $keep = [];

            foreach ($this->seen as $piecesCount => $players) {
                $keep[] = sprintf('(pieces_count = %d AND player_id = ANY(CAST(:players%d AS uuid[])))', $piecesCount, $piecesCount);
            }

            $parameters = [];

            foreach ($this->seen as $piecesCount => $players) {
                $parameters['players' . $piecesCount] = '{' . implode(',', $players) . '}';
            }

            $result['deleted'] += (int) $this->connection->executeStatement(
                'DELETE FROM player_rating_entry WHERE NOT (' . ($keep === [] ? 'FALSE' : implode(' OR ', $keep)) . ')',
                $parameters,
            );
        }

        // Private players have no rating (the same cleanup as player_elo); --player may have scored one
        $result['deleted'] += (int) $this->connection->executeStatement(
            'DELETE FROM player_rating_entry WHERE player_id IN (SELECT id FROM player WHERE is_private = true)',
        );

        return $result;
    }

    private function flush(): void
    {
        if ($this->chunkPlayers === [] || $this->chunkPiecesCount === null) {
            return;
        }

        $this->run->sync(
            $this->now,
            'pieces_count = :piecesCount AND player_id = ANY(CAST(:players AS uuid[]))',
            ['piecesCount' => $this->chunkPiecesCount, 'players' => '{' . implode(',', $this->chunkPlayers) . '}'],
        );

        $this->chunkPlayers = [];
    }
}
