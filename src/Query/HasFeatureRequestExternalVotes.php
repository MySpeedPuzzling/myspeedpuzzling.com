<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class HasFeatureRequestExternalVotes
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function __invoke(string $featureRequestId): bool
    {
        $query = <<<SQL
SELECT COUNT(*)
FROM feature_request_vote fv
JOIN feature_request fr ON fv.feature_request_id = fr.id
WHERE fv.feature_request_id = :featureRequestId AND fv.voter_id != fr.author_id
SQL;

        $result = $this->database->executeQuery($query, [
            'featureRequestId' => $featureRequestId,
        ])->fetchOne();

        return is_numeric($result) && (int) $result > 0;
    }
}
