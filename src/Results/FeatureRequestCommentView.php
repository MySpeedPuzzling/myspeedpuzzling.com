<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * @phpstan-type FeatureRequestCommentViewRow array{
 *     id: string,
 *     content: string,
 *     created_at: string,
 *     author_id: string,
 *     author_name: string,
 *     author_avatar: null|string,
 * }
 */
readonly final class FeatureRequestCommentView
{
    public function __construct(
        public string $id,
        public string $content,
        public string $createdAt,
        public string $authorId,
        public string $authorName,
        public null|string $authorAvatar,
    ) {
    }

    /**
     * @param FeatureRequestCommentViewRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: $row['id'],
            content: $row['content'],
            createdAt: $row['created_at'],
            authorId: $row['author_id'],
            authorName: $row['author_name'],
            authorAvatar: $row['author_avatar'],
        );
    }
}
