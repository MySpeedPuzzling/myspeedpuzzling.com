<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The calibration bucket of a prediction window: first try or repeat × the player's consistency quartile × (first
 * tries only) how many difficulty indices the puzzle had × piece-count group. The quantile table of the fitted model
 * is keyed by key(); a bucket with too few samples falls back along fallbackKeys().
 */
readonly final class WindowBucket
{
    public const array K_BUCKETS = [0, 1, 5, 20];

    private function __construct(
        public bool $firstTry,
        public int $consistency,
        public null|int $kBucket,
        public PiecesGroup $group,
    ) {
    }

    /**
     * @param list<float> $consistencyEdges the fitted quartile edges of the player spread (3 values)
     */
    public static function firstTry(float $playerSpread, array $consistencyEdges, int $k, PiecesGroup $group): self
    {
        return new self(true, self::quartile($playerSpread, $consistencyEdges), self::kBucket($k), $group);
    }

    /**
     * @param list<float> $consistencyEdges
     */
    public static function repeat(float $playerSpread, array $consistencyEdges, PiecesGroup $group): self
    {
        return new self(false, self::quartile($playerSpread, $consistencyEdges), null, $group);
    }

    public static function kBucket(int $k): int
    {
        return match (true) {
            $k <= 0 => 0,
            $k < 5 => 1,
            $k < 20 => 5,
            default => 20,
        };
    }

    /**
     * @param list<float> $edges
     */
    public static function quartile(float $value, array $edges): int
    {
        $quartile = 0;

        foreach ($edges as $edge) {
            if ($value > $edge) {
                $quartile++;
            }
        }

        return min($quartile, 3);
    }

    public function key(): string
    {
        return $this->firstTry
            ? sprintf('f|c%d|k%d|%s', $this->consistency, $this->kBucket, $this->group->value)
            : sprintf('r|c%d|%s', $this->consistency, $this->group->value);
    }

    /**
     * From the most to the least specific: the bucket, without the size group, the kind with the k bucket (first
     * tries), the kind alone.
     *
     * @return list<string>
     */
    public function fallbackKeys(): array
    {
        if ($this->firstTry) {
            return [
                $this->key(),
                sprintf('f|c%d|k%d', $this->consistency, $this->kBucket),
                sprintf('f|k%d', $this->kBucket),
                'f',
            ];
        }

        return [
            $this->key(),
            sprintf('r|c%d', $this->consistency),
            'r',
        ];
    }
}
