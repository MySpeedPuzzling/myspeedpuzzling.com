<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The batch generation: a number the insights batch moves on at the end of every run (and the daily profile run),
 * so anything cached from batch-computed data (the Insights page cache) can key on it and never outlive the data
 * it was built from. One row in insights_generation; reading it is one primary-key lookup. 0 = no run yet.
 */
readonly final class InsightsGeneration
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function current(): int
    {
        /** @var false|int|string $generation */
        $generation = $this->connection->fetchOne('SELECT generation FROM insights_generation WHERE id = 1');

        return $generation === false ? 0 : (int) $generation;
    }

    /**
     * Called by the batch after its last write. Returns the new generation.
     */
    public function advance(): int
    {
        /** @var int|string $generation */
        $generation = $this->connection->fetchOne('
            INSERT INTO insights_generation (id, generation, advanced_at)
            VALUES (1, 1, :now)
            ON CONFLICT (id) DO UPDATE SET
                generation = insights_generation.generation + 1,
                advanced_at = EXCLUDED.advanced_at
            RETURNING generation
        ', ['now' => $this->clock->now()->format('Y-m-d H:i:s')]);

        return (int) $generation;
    }
}
