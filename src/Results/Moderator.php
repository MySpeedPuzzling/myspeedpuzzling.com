<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class Moderator
{
    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|CountryCode $playerCountry,
        public null|string $playerAvatar,
        public DateTimeImmutable $moderatorSince,
        public int $reviewedChangeRequests,
        public int $reviewedMergeRequests,
        public null|DateTimeImmutable $lastReviewAt,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     player_avatar: null|string,
     *     moderator_since: string,
     *     reviewed_change_requests: int|string,
     *     reviewed_merge_requests: int|string,
     *     last_review_at: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: $row['player_code'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            playerAvatar: $row['player_avatar'],
            moderatorSince: new DateTimeImmutable($row['moderator_since']),
            reviewedChangeRequests: (int) $row['reviewed_change_requests'],
            reviewedMergeRequests: (int) $row['reviewed_merge_requests'],
            lastReviewAt: $row['last_review_at'] !== null ? new DateTimeImmutable($row['last_review_at']) : null,
        );
    }
}
