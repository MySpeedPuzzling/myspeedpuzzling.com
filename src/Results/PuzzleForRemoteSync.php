<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleForRemoteSync
{
    public function __construct(
        public string $puzzleId,
        public string $ean,
        public null|string $remoteUrl,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     ean: string,
     *     remote_puzzle_puzzle_url: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            ean: $row['ean'],
            remoteUrl: $row['remote_puzzle_puzzle_url'],
        );
    }
}
