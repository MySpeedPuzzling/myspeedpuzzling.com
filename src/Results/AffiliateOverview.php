<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\AffiliateStatus;

readonly final class AffiliateOverview
{
    public function __construct(
        public string $affiliateId,
        public string $playerId,
        public null|string $playerName,
        public null|string $playerAvatar,
        public string $code,
        public AffiliateStatus $status,
        public DateTimeImmutable $createdAt,
        public int $supporterCount,
        public int $totalEarnedCents,
        public int $pendingPayoutCents,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $affiliateId = $row['affiliate_id'];
        assert(is_string($affiliateId));
        $playerId = $row['player_id'];
        assert(is_string($playerId));
        $code = $row['code'];
        assert(is_string($code));
        $createdAt = $row['created_at'];
        assert(is_string($createdAt));

        $statusString = $row['status'];
        assert(is_string($statusString));
        $status = AffiliateStatus::from($statusString);

        /** @var int|string $supporterCount */
        $supporterCount = $row['supporter_count'] ?? 0;
        /** @var int|string $totalEarnedCents */
        $totalEarnedCents = $row['total_earned_cents'] ?? 0;
        /** @var int|string $pendingPayoutCents */
        $pendingPayoutCents = $row['pending_payout_cents'] ?? 0;

        return new self(
            affiliateId: $affiliateId,
            playerId: $playerId,
            playerName: is_string($row['player_name']) ? $row['player_name'] : null,
            playerAvatar: is_string($row['player_avatar'] ?? null) ? $row['player_avatar'] : null,
            code: $code,
            status: $status,
            createdAt: new DateTimeImmutable($createdAt),
            supporterCount: (int) $supporterCount,
            totalEarnedCents: (int) $totalEarnedCents,
            pendingPayoutCents: (int) $pendingPayoutCents,
        );
    }
}
