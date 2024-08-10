<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class NotConnectedWjpcParticipant
{
    public function __construct(
        public string $wjpcName,
        public null|int $rank2023,
    ) {
    }

    /**
     * @param array{
     *     wjpc_name: string,
     *     rank_2023: null|int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            wjpcName: $row['wjpc_name'],
            rank2023: $row['rank_2023'],
        );
    }
}
