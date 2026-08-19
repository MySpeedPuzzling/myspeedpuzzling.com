<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class PuzzleManufacturerResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
