<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class SolvedPuzzleDetail
{
    public function __construct(
        public string $timeId,
        public null|string $teamId,
        public string $playerId,
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleNames $puzzleAlternativeNames,
        public string $manufacturerName,
        public string $manufacturerId,
        public int $piecesCount,
        public null|int $time,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public null|string $comment,
        /** @var null|array<Puzzler> */
        public null|array $players,
        public null|DateTimeImmutable $finishedAt,
        public null|string $finishedPuzzlePhoto,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $competitionId,
        // The puzzle has an image, held back until a competition round starts (puzzleImage is null then)
        public bool $puzzleImageHidden = false,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     team_id: null|string,
     *     player_id: string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_names: string,
     *     manufacturer_name: string,
     *     manufacturer_id: string,
     *     puzzle_image: null|string,
     *     puzzle_image_ratio: null|string,
     *     puzzle_image_hidden: bool,
     *     time: null|int,
     *     pieces_count: int,
     *     comment: null|string,
     *     players: null|string,
     *     finished_at: null|string,
     *     finished_puzzle_photo: string,
     *     first_attempt: bool,
     *     unboxed: bool,
     *     competition_id: null|string,
     *  } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;
        if ($row['players'] !== null) {
            $players = Puzzler::createPuzzlersFromJson($row['players'], $row['player_id']);
        }

        return new self(
            timeId: $row['time_id'],
            teamId: $row['team_id'],
            playerId: $row['player_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeNames: PuzzleNames::fromJson($row['puzzle_alternative_names']),
            manufacturerName: $row['manufacturer_name'],
            manufacturerId: $row['manufacturer_id'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
            puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
            comment: $row['comment'],
            players: $players,
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            finishedPuzzlePhoto: $row['finished_puzzle_photo'],
            firstAttempt: $row['first_attempt'],
            unboxed: $row['unboxed'],
            competitionId: $row['competition_id'],
            puzzleImageHidden: $row['puzzle_image_hidden'],
        );
    }

    /**
     * Whoever tracked the time and every registered group member may edit it,
     * mirroring PuzzleSolvingTime::canBeModifiedBy().
     */
    public function isEditableBy(null|string $playerId): bool
    {
        if ($playerId === null) {
            return false;
        }

        return $playerId === $this->playerId || Puzzler::listContainsPlayer($this->players, $playerId);
    }
}
