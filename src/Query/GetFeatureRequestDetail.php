<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\FeatureRequestNotFound;
use SpeedPuzzling\Web\Results\FeatureRequestDetail;

readonly final class GetFeatureRequestDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws FeatureRequestNotFound
     */
    public function byId(string $featureRequestId): FeatureRequestDetail
    {
        if (!Uuid::isValid($featureRequestId)) {
            throw new FeatureRequestNotFound();
        }

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
    p.id AS author_id,
    COALESCE(p.name, '#' || UPPER(p.code)) AS author_name,
    p.avatar AS author_avatar,
    p.country AS author_country
FROM feature_request fr
JOIN player p ON fr.author_id = p.id
WHERE fr.id = :featureRequestId
SQL;

        $row = $this->database->executeQuery($query, [
            'featureRequestId' => $featureRequestId,
        ])->fetchAssociative();

        if ($row === false) {
            throw new FeatureRequestNotFound();
        }

        /** @var array{id: string, title: string, description: string, created_at: string, vote_count: int|string, author_id: string, author_name: string, author_avatar: null|string, author_country: null|string, status: string, github_url: null|string, admin_comment: null|string} $row */
        return FeatureRequestDetail::fromDatabaseRow($row);
    }
}
