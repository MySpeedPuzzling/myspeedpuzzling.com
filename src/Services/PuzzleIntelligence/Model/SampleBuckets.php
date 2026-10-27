<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use LogicException;
use SpeedPuzzling\Web\Value\WindowBucket;

/**
 * The standardised errors of a window calibration (PredictionModelFitter steps 4 and 5) by their WindowBucket, for the
 * quantile table of every fallback key - without a list per key holding each error once per key: a first try belongs
 * to four keys (its bucket, without the size group, with the k bucket only, the kind alone), so those lists held four
 * values per sample at once, ~15 MB over the ~150k first tries of the daily fit (it runs in a 512 MB container). Here
 * every error is kept once, with its bucket; the keys' lists are built one fallback level at a time.
 *
 * quantileTable() gives what the table of the per-key lists gave: each key's errors in the order they were added, the
 * keys in the order they were first met (a sample's keys in their fallbackKeys() order).
 */
final class SampleBuckets
{
    /** @var list<float> */
    private array $errors = [];
    /** @var list<int> sample => its bucket */
    private array $bucketOf = [];
    /** @var array<string, int> bucket key => bucket */
    private array $bucketIds = [];
    /** @var list<list<string>> bucket => its fallbackKeys(), the buckets in the order of their first sample */
    private array $bucketKeys = [];

    public function add(float $error, WindowBucket $bucket): void
    {
        // A bucket's key holds every part of it, so it stands for its fallback keys
        $key = $bucket->key();

        if (!isset($this->bucketIds[$key])) {
            $this->bucketIds[$key] = count($this->bucketKeys);
            $this->bucketKeys[] = $bucket->fallbackKeys();
        }

        $this->errors[] = $error;
        $this->bucketOf[] = $this->bucketIds[$key];
    }

    /**
     * @param callable(array<string, list<float>>): array<string, array{0: float, 1: float, 2: float, 3: float}> $quantileTable the table of per-key lists
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public function quantileTable(callable $quantileTable): array
    {
        // A key is first met with the first sample of the first bucket having it - so in the buckets' order
        $order = [];
        $levelOf = [];

        foreach ($this->bucketKeys as $keys) {
            foreach ($keys as $level => $key) {
                $order[$key] ??= count($order);

                if (($levelOf[$key] ??= $level) !== $level) {
                    throw new LogicException(sprintf('Window key "%s" on two fallback levels', $key));
                }
            }
        }

        $table = [];
        $levels = count($this->bucketKeys[0] ?? []);

        for ($level = 0; $level < $levels; $level++) {
            $keyOfBucket = array_map(static fn (array $keys): string => $keys[$level], $this->bucketKeys);
            $byKey = [];

            foreach ($this->errors as $sample => $error) {
                $byKey[$keyOfBucket[$this->bucketOf[$sample]]][] = $error;
            }

            $table += $quantileTable($byKey);
            unset($byKey);
        }

        $ordered = [];

        foreach (array_keys($order) as $key) {
            if (isset($table[$key])) {
                $ordered[$key] = $table[$key];
            }
        }

        return $ordered;
    }
}
