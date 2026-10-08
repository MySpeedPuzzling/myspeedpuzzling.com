<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * The public-visibility rule of a series row (docs/features/organizations/README.md "Visibility"): approved, not
 * rejected, not a draft. Its editions follow it through IsCompetitionPubliclyVisible. An organization's state never
 * hides a series. CompetitionSeries::isPubliclyVisible() is its PHP mirror (VisibilityParityTest).
 */
readonly final class IsSeriesPubliclyVisible
{
    /**
     * The rule as a WHERE fragment - alias `cs` = competition_series
     */
    public const string SQL_CONDITION = '(cs.approved_at IS NOT NULL AND cs.rejected_at IS NULL AND cs.is_draft = false)';

    public function __construct(
        private Connection $database,
    ) {
    }

    public function check(string $seriesId): bool
    {
        if (Uuid::isValid($seriesId) === false) {
            return false;
        }

        $condition = self::SQL_CONDITION;

        $result = $this->database
            ->executeQuery("SELECT 1 FROM competition_series cs WHERE cs.id = :seriesId AND {$condition} LIMIT 1", [
                'seriesId' => $seriesId,
            ])
            ->fetchOne();

        return $result !== false;
    }
}
