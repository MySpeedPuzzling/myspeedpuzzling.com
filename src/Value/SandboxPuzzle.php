<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A catalogue puzzle the simulation may log results for (GetSandboxSimulationData::puzzlePool()).
 */
final readonly class SandboxPuzzle
{
    public function __construct(
        public string $id,
        public int $piecesCount,
    ) {
    }
}
