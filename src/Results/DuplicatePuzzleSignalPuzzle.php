<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One of the two puzzle records of a catalogue signal, as the admin compares them.
 */
readonly final class DuplicatePuzzleSignalPuzzle
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $image,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $ean,
        public null|string $identificationNumber,
        public bool $approved,
        public int $results,
    ) {
    }
}
