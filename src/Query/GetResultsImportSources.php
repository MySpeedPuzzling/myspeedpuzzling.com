<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ResultsImport\ResultsImportSource;

/**
 * The results sources of an organization (docs/features/event-results-import/results-import-page.md "Pages") - set up
 * by admins only (P1), read by its results import page.
 */
readonly final class GetResultsImportSources
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<ResultsImportSource> oldest first
     */
    public function ofOrganization(string $organizationId): array
    {
        if (Uuid::isValid($organizationId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT s.id, s.organization_id, s.format, s.added_at,
    (SELECT MAX(c.checked_at) FROM results_import_check c WHERE c.source_id = s.id) AS last_checked_at
FROM event_results_sheet s
WHERE s.organization_id = :organizationId
ORDER BY s.added_at, s.id
SQL;

        /** @var list<array{id: string, organization_id: string, format: string, added_at: string, last_checked_at: null|string}> $rows */
        $rows = $this->database->executeQuery($query, ['organizationId' => $organizationId])->fetchAllAssociative();

        return array_map(static fn (array $row): ResultsImportSource => new ResultsImportSource(
            id: $row['id'],
            organizationId: $row['organization_id'],
            format: $row['format'],
            addedAt: new DateTimeImmutable($row['added_at']),
            lastCheckedAt: $row['last_checked_at'] !== null ? new DateTimeImmutable($row['last_checked_at']) : null,
        ), $rows);
    }
}
