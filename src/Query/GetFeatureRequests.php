<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\FeatureRequestOverview;

readonly final class GetFeatureRequests
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<FeatureRequestOverview>
     */
    public function allSortedByVotes(): array
    {
        $query = <<<SQL
SELECT
    fr.id,
    fr.title,
    fr.description,
    fr.created_at,
    fr.vote_count,
    (SELECT COUNT(*) FROM feature_request_comment frc WHERE frc.feature_request_id = fr.id) AS comment_count,
    p.id AS author_id,
    COALESCE(p.name, '#' || UPPER(p.code)) AS author_name,
    p.avatar AS author_avatar,
    p.country AS author_country
FROM feature_request fr
JOIN player p ON fr.author_id = p.id
ORDER BY fr.vote_count DESC, fr.created_at DESC
SQL;

        $rows = $this->database->executeQuery($query)->fetchAllAssociative();

        return array_map(static function (array $row): FeatureRequestOverview {
            /** @var array{id: string, title: string, description: string, created_at: string, vote_count: int|string, comment_count: int|string, author_id: string, author_name: string, author_avatar: null|string, author_country: null|string} $row */
            return FeatureRequestOverview::fromDatabaseRow($row);
        }, $rows);
    }
}
