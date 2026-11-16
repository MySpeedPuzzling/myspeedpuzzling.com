<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

/**
 * The puzzle a sheet links to, as the import will use it - a merged puzzle's survivor.
 */
readonly final class ResultsImportPuzzle
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
        public null|float $imageRatio,
        public bool $approved,
    ) {
    }
}
