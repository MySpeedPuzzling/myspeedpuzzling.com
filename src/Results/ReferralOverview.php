<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ReferralSource;

readonly final class ReferralOverview
{
    public function __construct(
        public string $referralId,
        public string $subscriberId,
        public null|string $subscriberName,
        public null|string $subscriberAvatar,
        public string $affiliateId,
        public null|string $affiliatePlayerName,
        public string $affiliateCode,
        public ReferralSource $source,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $referralId = $row['referral_id'];
        assert(is_string($referralId));
        $subscriberId = $row['subscriber_id'];
        assert(is_string($subscriberId));
        $affiliateId = $row['affiliate_id'];
        assert(is_string($affiliateId));
        $affiliateCode = $row['affiliate_code'];
        assert(is_string($affiliateCode));
        $createdAt = $row['created_at'];
        assert(is_string($createdAt));

        $sourceString = $row['source'];
        assert(is_string($sourceString));

        return new self(
            referralId: $referralId,
            subscriberId: $subscriberId,
            subscriberName: is_string($row['subscriber_name'] ?? null) ? $row['subscriber_name'] : null,
            subscriberAvatar: is_string($row['subscriber_avatar'] ?? null) ? $row['subscriber_avatar'] : null,
            affiliateId: $affiliateId,
            affiliatePlayerName: is_string($row['affiliate_player_name'] ?? null) ? $row['affiliate_player_name'] : null,
            affiliateCode: $affiliateCode,
            source: ReferralSource::from($sourceString),
            createdAt: new DateTimeImmutable($createdAt),
        );
    }
}
