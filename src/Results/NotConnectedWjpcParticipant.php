<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class NotConnectedWjpcParticipant
{
    public function __construct(
        public string $wjpcName,
        public null|int $rank2023,
        /** @var array<string> */
        public array $rounds,
    ) {
    }

    /**
     * @param array{
     *     wjpc_name: string,
     *     rank_2023: null|int,
     *     rounds: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $rounds = [];
        if (json_validate($row['rounds'])) {
            /** @var array<string> $rounds */
            $rounds = json_decode($row['rounds'], true);
        }

        return new self(
            wjpcName: $row['wjpc_name'],
            rank2023: $row['rank_2023'],
            rounds: $rounds,
        );
    }
}
