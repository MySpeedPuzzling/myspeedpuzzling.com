<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class CompetitionRoundPuzzleResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public int $pieces_count,
        public null|string $image,
        public null|string $manufacturer_name,
    ) {
    }
}
