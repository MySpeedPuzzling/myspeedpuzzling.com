<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * @phpstan-type FeatureRequestOverviewRow array{
 *     id: string,
 *     title: string,
 *     description: string,
 *     created_at: string,
 *     vote_count: int|string,
 *     comment_count: int|string,
 *     author_id: string,
 *     author_name: string,
 *     author_avatar: null|string,
 *     author_country: null|string,
 * }
 */
readonly final class FeatureRequestOverview
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $createdAt,
        public int $voteCount,
        public int $commentCount,
        public string $authorId,
        public string $authorName,
        public null|string $authorAvatar,
        public null|string $authorCountry,
    ) {
    }

    /**
     * @param FeatureRequestOverviewRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: $row['id'],
            title: $row['title'],
            description: $row['description'],
            createdAt: $row['created_at'],
            voteCount: (int) $row['vote_count'],
            commentCount: (int) $row['comment_count'],
            authorId: $row['author_id'],
            authorName: $row['author_name'],
            authorAvatar: $row['author_avatar'],
            authorCountry: $row['author_country'],
        );
    }
}
