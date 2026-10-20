<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class ModeratorInvitationRow
{
    public function __construct(
        public string $invitationId,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|CountryCode $playerCountry,
        public null|string $invitedByName,
        public DateTimeImmutable $invitedAt,
        /** 'pending' | 'declined' | 'withdrawn' */
        public string $status,
        public null|DateTimeImmutable $closedAt,
    ) {
    }

    /**
     * @param array{
     *     invitation_id: string,
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     invited_by_name: null|string,
     *     invited_at: string,
     *     answered_at: null|string,
     *     withdrawn_at: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $closedAt = $row['withdrawn_at'] ?? $row['answered_at'];

        return new self(
            invitationId: $row['invitation_id'],
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: $row['player_code'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            invitedByName: $row['invited_by_name'],
            invitedAt: new DateTimeImmutable($row['invited_at']),
            status: match (true) {
                $row['withdrawn_at'] !== null => 'withdrawn',
                $row['answered_at'] !== null => 'declined',
                default => 'pending',
            },
            closedAt: $closedAt !== null ? new DateTimeImmutable($closedAt) : null,
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
