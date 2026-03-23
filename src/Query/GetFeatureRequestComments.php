<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\FeatureRequestCommentView;

readonly final class GetFeatureRequestComments
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<FeatureRequestCommentView>
     */
    public function forFeatureRequest(string $featureRequestId): array
    {
        $query = <<<SQL
SELECT
    frc.id,
    frc.content,
    frc.created_at,
    p.id AS author_id,
    COALESCE(p.name, '#' || UPPER(p.code)) AS author_name,
    p.avatar AS author_avatar
FROM feature_request_comment frc
JOIN player p ON frc.author_id = p.id
WHERE frc.feature_request_id = :featureRequestId
ORDER BY frc.created_at DESC
SQL;

        $rows = $this->database->executeQuery($query, [
            'featureRequestId' => $featureRequestId,
        ])->fetchAllAssociative();

        return array_map(static function (array $row): FeatureRequestCommentView {
            /** @var array{id: string, content: string, created_at: string, author_id: string, author_name: string, author_avatar: null|string} $row */
            return FeatureRequestCommentView::fromDatabaseRow($row);
        }, $rows);
    }
}
