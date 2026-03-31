<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\FeatureRequestOverview;
use SpeedPuzzling\Web\Value\FeatureRequestStatus;

readonly final class GetFeatureRequests
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<FeatureRequestOverview>
     */
    public function findAll(
        null|FeatureRequestStatus $status = null,
        null|string $authorId = null,
        string $sort = 'most_votes',
    ): array {
        $whereClauses = [];
        $params = [];

        if ($status !== null) {
            $whereClauses[] = 'fr.status = :status';
            $params['status'] = $status->value;
        }

        if ($authorId !== null) {
            $whereClauses[] = 'fr.author_id = :authorId';
            $params['authorId'] = $authorId;
        }

        $where = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $orderBy = match ($sort) {
            'least_votes' => 'vote_count ASC, fr.created_at DESC',
            'newest' => 'fr.created_at DESC',
            'oldest' => 'fr.created_at ASC',
            default => 'vote_count DESC, fr.created_at DESC',
        };

        $query = <<<SQL
SELECT
    fr.id,
    fr.title,
    fr.description,
    fr.created_at,
    fr.status,
    fr.github_url,
    fr.admin_comment,
    1 + (SELECT COUNT(*) FROM feature_request_vote fv WHERE fv.feature_request_id = fr.id) AS vote_count,
    (SELECT COUNT(*) FROM feature_request_comment frc WHERE frc.feature_request_id = fr.id) AS comment_count,
    p.id AS author_id,
    COALESCE(p.name, '#' || UPPER(p.code)) AS author_name,
    p.avatar AS author_avatar,
    p.country AS author_country
FROM feature_request fr
JOIN player p ON fr.author_id = p.id
{$where}
ORDER BY {$orderBy}
SQL;

        $rows = $this->database->executeQuery($query, $params)->fetchAllAssociative();

        return array_map(static function (array $row): FeatureRequestOverview {
            /** @var array{id: string, title: string, description: string, created_at: string, vote_count: int|string, comment_count: int|string, author_id: string, author_name: string, author_avatar: null|string, author_country: null|string, status: string, github_url: null|string, admin_comment: null|string} $row */
            return FeatureRequestOverview::fromDatabaseRow($row);
        }, $rows);
    }
}
