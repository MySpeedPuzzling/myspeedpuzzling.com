<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\TransferType;

readonly final class PlayerNotification
{
    public function __construct(
        public DateTimeImmutable $notifiedAt,
        public null|DateTimeImmutable $readAt,
        public null|NotificationType $notificationType,
        // Puzzle solving notification fields
        public null|string $targetPlayerId,
        public null|string $targetPlayerName,
        public null|string $targetPlayerAvatar,
        public null|CountryCode $targetPlayerCountry,
        public null|string $puzzleId,
        public null|string $puzzleName,
        public null|string $puzzleAlternativeName,
        public null|string $manufacturerName,
        public null|int $piecesCount,
        public null|int $time,
        public null|string $puzzleImage,
        public null|string $teamId,
        /** @var null|array<Puzzler> */
        public null|array $players,
        // Lending notification fields
        public null|string $transferId = null,
        public null|TransferType $transferType = null,
        public null|string $fromPlayerId = null,
        public null|string $fromPlayerName = null,
        public null|string $fromPlayerAvatar = null,
        public null|string $toPlayerId = null,
        public null|string $toPlayerName = null,
        public null|string $toPlayerAvatar = null,
        public null|string $ownerPlayerId = null,
        public null|string $ownerPlayerName = null,
        public null|string $lendingPuzzleId = null,
        public null|string $lendingPuzzleName = null,
        public null|string $lendingPuzzleImage = null,
        public null|string $lendingManufacturerName = null,
        public null|int $lendingPiecesCount = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;
        $playersData = $row['players'] ?? null;
        if (is_string($playersData)) {
            $players = Puzzler::createPuzzlersFromJson($playersData);
        }

        $readAt = null;
        $readAtData = $row['read_at'] ?? null;
        if (is_string($readAtData)) {
            $readAt = new DateTimeImmutable($readAtData);
        }

        $notifiedAt = $row['notified_at'];
        assert(is_string($notifiedAt));

        $notificationType = $row['notification_type'];
        assert(is_string($notificationType));

        $targetPlayerId = $row['target_player_id'] ?? null;
        $targetPlayerName = $row['target_player_name'] ?? $row['target_player_code'] ?? null;
        $targetPlayerAvatar = $row['target_player_avatar'] ?? null;
        $targetPlayerCountry = $row['target_player_country'] ?? null;
        $puzzleId = $row['puzzle_id'] ?? null;
        $puzzleName = $row['puzzle_name'] ?? null;
        $puzzleAlternativeName = $row['puzzle_alternative_name'] ?? null;
        $manufacturerName = $row['manufacturer_name'] ?? null;
        $puzzleImage = $row['puzzle_image'] ?? null;
        $teamId = $row['team_id'] ?? null;
        $transferId = $row['transfer_id'] ?? null;
        $transferType = $row['transfer_type'] ?? null;
        $fromPlayerId = $row['from_player_id'] ?? null;
        $fromPlayerName = $row['from_player_name'] ?? null;
        $fromPlayerAvatar = $row['from_player_avatar'] ?? null;
        $toPlayerId = $row['to_player_id'] ?? null;
        $toPlayerName = $row['to_player_name'] ?? null;
        $toPlayerAvatar = $row['to_player_avatar'] ?? null;
        $ownerPlayerId = $row['owner_player_id'] ?? null;
        $ownerPlayerName = $row['owner_player_name'] ?? null;
        $lendingPuzzleId = $row['lending_puzzle_id'] ?? null;
        $lendingPuzzleName = $row['lending_puzzle_name'] ?? null;
        $lendingPuzzleImage = $row['lending_puzzle_image'] ?? null;
        $lendingManufacturerName = $row['lending_manufacturer_name'] ?? null;

        return new self(
            notifiedAt: new DateTimeImmutable($notifiedAt),
            readAt: $readAt,
            notificationType: NotificationType::tryFrom($notificationType),
            // Puzzle solving fields
            targetPlayerId: is_string($targetPlayerId) ? $targetPlayerId : null,
            targetPlayerName: is_string($targetPlayerName) ? $targetPlayerName : null,
            targetPlayerAvatar: is_string($targetPlayerAvatar) ? $targetPlayerAvatar : null,
            targetPlayerCountry: is_string($targetPlayerCountry) ? CountryCode::fromCode($targetPlayerCountry) : null,
            puzzleId: is_string($puzzleId) ? $puzzleId : null,
            puzzleName: is_string($puzzleName) ? $puzzleName : null,
            puzzleAlternativeName: is_string($puzzleAlternativeName) ? $puzzleAlternativeName : null,
            manufacturerName: is_string($manufacturerName) ? $manufacturerName : null,
            piecesCount: isset($row['pieces_count']) && is_numeric($row['pieces_count']) ? (int) $row['pieces_count'] : null,
            time: isset($row['time']) && is_numeric($row['time']) ? (int) $row['time'] : null,
            puzzleImage: is_string($puzzleImage) ? $puzzleImage : null,
            teamId: is_string($teamId) ? $teamId : null,
            players: $players,
            // Lending fields
            transferId: is_string($transferId) ? $transferId : null,
            transferType: is_string($transferType) ? TransferType::tryFrom($transferType) : null,
            fromPlayerId: is_string($fromPlayerId) ? $fromPlayerId : null,
            fromPlayerName: is_string($fromPlayerName) ? $fromPlayerName : null,
            fromPlayerAvatar: is_string($fromPlayerAvatar) ? $fromPlayerAvatar : null,
            toPlayerId: is_string($toPlayerId) ? $toPlayerId : null,
            toPlayerName: is_string($toPlayerName) ? $toPlayerName : null,
            toPlayerAvatar: is_string($toPlayerAvatar) ? $toPlayerAvatar : null,
            ownerPlayerId: is_string($ownerPlayerId) ? $ownerPlayerId : null,
            ownerPlayerName: is_string($ownerPlayerName) ? $ownerPlayerName : null,
            lendingPuzzleId: is_string($lendingPuzzleId) ? $lendingPuzzleId : null,
            lendingPuzzleName: is_string($lendingPuzzleName) ? $lendingPuzzleName : null,
            lendingPuzzleImage: is_string($lendingPuzzleImage) ? $lendingPuzzleImage : null,
            lendingManufacturerName: is_string($lendingManufacturerName) ? $lendingManufacturerName : null,
            lendingPiecesCount: isset($row['lending_pieces_count']) && is_numeric($row['lending_pieces_count']) ? (int) $row['lending_pieces_count'] : null,
        );
    }

    public function isLendingNotification(): bool
    {
        return $this->transferId !== null;
    }

    public function isPuzzleSolvingNotification(): bool
    {
        return $this->targetPlayerId !== null;
    }
}
