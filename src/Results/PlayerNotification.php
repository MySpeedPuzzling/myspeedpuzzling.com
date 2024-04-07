<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class PlayerNotification
{
    public function __construct(
        public DateTimeImmutable $notifiedAt,
        public null|DateTimeImmutable $readAt,
        public null|NotificationType $notificationType,
        public string $targetPlayerId,
        public string $targetPlayerName,
        public null|string $targetPlayerAvatar,
        public null|CountryCode $targetPlayerCountry,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public int $time,
        public null|string $puzzleImage,
        public null|string $teamId,
        /** @var null|array<Puzzler> */
        public null|array $players,
    ) {
    }

    /**
     * @param array{
     *     notified_at: string,
     *     read_at: null|string,
     *     notification_type: string,
     *     target_player_id: string,
     *     target_player_name: string,
     *     target_player_avatar: null|string,
     *     target_player_country: null|string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     manufacturer_name: string,
     *     pieces_count: int,
     *     time: int,
     *     puzzle_image: null|string,
     *     team_id: null|string,
     *     players: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;
        if (is_string($row['players'] ?? null)) {
            $players = Puzzler::createPuzzlersFromJson($row['players']);
        }

        $readAt = null;
        if ($row['read_at'] !== null) {
            $readAt = new DateTimeImmutable($row['read_at']);
        }

        return new self(
            notifiedAt: new DateTimeImmutable($row['notified_at']),
            readAt: $readAt,
            notificationType: NotificationType::tryFrom($row['notification_type']),
            targetPlayerId: $row['target_player_id'],
            targetPlayerName: $row['target_player_name'],
            targetPlayerAvatar: $row['target_player_avatar'],
            targetPlayerCountry: CountryCode::fromCode($row['target_player_country']),
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
            teamId: $row['team_id'] ?? null,
            players: $players,
        );
    }
}
