<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class TrackedPuzzleDetail
{
    public function __construct(
        public string $trackingId,
        public null|string $teamId,
        public string $playerId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public string $manufacturerId,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|string $comment,
        /** @var null|array<Puzzler> */
        public null|array $players,
        public null|DateTimeImmutable $finishedAt,
        public null|string $finishedPuzzlePhoto,
    ) {
    }

    /**
     * @param array{
     *     tracking_id: string,
     *     team_id: null|string,
     *     player_id: string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     manufacturer_name: string,
     *     manufacturer_id: string,
     *     puzzle_image: null|string,
     *     pieces_count: int,
     *     comment: null|string,
     *     players: null|string,
     *     finished_at: null|string,
     *     finished_puzzle_photo: null|string,
     *  } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;
        if ($row['players'] !== null) {
            $players = Puzzler::createPuzzlersFromJson($row['players'], $row['player_id']);
        }

        return new self(
            trackingId: $row['tracking_id'],
            teamId: $row['team_id'],
            playerId: $row['player_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            manufacturerId: $row['manufacturer_id'],
            piecesCount: $row['pieces_count'],
            puzzleImage: $row['puzzle_image'],
            comment: $row['comment'],
            players: $players,
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            finishedPuzzlePhoto: $row['finished_puzzle_photo'],
        );
    }
}
