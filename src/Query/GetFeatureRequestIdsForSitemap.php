<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetFeatureRequestIdsForSitemap
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string>
     */
    public function all(): array
    {
        $query = <<<SQL
SELECT id FROM feature_request
SQL;

        /** @var array<string> $ids */
        $ids = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $ids;
    }
}
