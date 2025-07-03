<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class NotConnectedCompetitionParticipant
{
    public function __construct(
        public string $id,
        public string $name,
        public null|int $rank2023,
        /** @var array<string> */
        public array $rounds,
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     rank_2023?: null|int,
     *     rounds?: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $rounds = [];
        if (isset($row['rounds']) && json_validate($row['rounds'])) {
            /** @var array<string> $rounds */
            $rounds = json_decode($row['rounds'], true);
        }

        return new self(
            id: $row['id'],
            name: $row['name'],
            rank2023: $row['rank_2023'] ?? null,
            rounds: $rounds,
        );
    }
}
