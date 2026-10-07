<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Every community pace reference, looked up by piece count and puzzling type.
 */
readonly final class PaceReferences
{
    /** @var array<string, PaceReference> */
    private array $references;

    /**
     * @param iterable<PaceReference> $references
     */
    public function __construct(iterable $references = [])
    {
        $byKey = [];

        foreach ($references as $reference) {
            $byKey[self::key($reference->piecesRange, $reference->puzzlingType)] = $reference;
        }

        $this->references = $byKey;
    }

    public function for(int $piecesCount, PuzzlingType $puzzlingType): null|PaceReference
    {
        return $this->references[self::key(SuspicionPiecesRange::of($piecesCount), $puzzlingType)] ?? null;
    }

    /**
     * These references, each replaced by a newer one of the same range and type.
     */
    public function overlaidWith(self $newer): self
    {
        return new self([...array_values($this->references), ...$newer->all()]);
    }

    /**
     * @return list<PaceReference>
     */
    public function all(): array
    {
        return array_values($this->references);
    }

    public function count(): int
    {
        return count($this->references);
    }

    private static function key(SuspicionPiecesRange $range, PuzzlingType $puzzlingType): string
    {
        return $range->value . '|' . $puzzlingType->value;
    }
}
