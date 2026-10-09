<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * The public-visibility rule of an organization row (docs/features/organizations/README.md "Visibility"): approved,
 * not rejected, not a draft. It decides the organization's own page being indexable, its directory entry, its
 * "Organized by" links, crumbs and follow star - **never** whether its series or events are public (D4).
 * Organization::isPubliclyVisible() is its PHP mirror (VisibilityParityTest).
 */
readonly final class IsOrganizationPubliclyVisible
{
    /**
     * The rule as a WHERE fragment - alias `o` = organization
     */
    public const string SQL_CONDITION = '(o.approved_at IS NOT NULL AND o.rejected_at IS NULL AND o.is_draft = false)';

    /**
     * The organization of a competition row: its own (a one-time event) or its series' (an edition). Needs `c` and `cs`
     * (competition_series LEFT JOINed on cs.id = c.series_id); joins `o`.
     */
    public const string SQL_JOIN_OF_COMPETITION = 'LEFT JOIN organization o ON o.id = COALESCE(c.organization_id, cs.organization_id)';

    public function __construct(
        private Connection $database,
    ) {
    }

    public function check(string $organizationId): bool
    {
        if (Uuid::isValid($organizationId) === false) {
            return false;
        }

        $condition = self::SQL_CONDITION;

        $result = $this->database
            ->executeQuery("SELECT 1 FROM organization o WHERE o.id = :organizationId AND {$condition} LIMIT 1", [
                'organizationId' => $organizationId,
            ])
            ->fetchOne();

        return $result !== false;
    }
}
