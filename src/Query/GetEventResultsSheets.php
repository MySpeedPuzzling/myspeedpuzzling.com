<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use SpeedPuzzling\Web\Exceptions\EventResultsSheetNotFound;

/**
 * The organizers' results sheets and what became of every tab (docs/features/event-results-import/README.md) - for the
 * internal API and the console command. Two statements whatever the number of sheets.
 */
readonly final class GetEventResultsSheets
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->sheets(null);
    }

    /**
     * @return array<string, mixed>
     * @throws EventResultsSheetNotFound
     */
    public function detail(string $sheetId): array
    {
        return $this->sheets($sheetId)[0] ?? throw new EventResultsSheetNotFound();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sheets(null|string $sheetId): array
    {
        /** @var list<array{id: string, spreadsheet_id: string, organization_id: string, acting_player_id: string, timezone: string, country_code: string, added_at: string, last_fetched_at: null|string, last_fetch_error: null|string}> $sheets */
        $sheets = $this->database->fetchAllAssociative(
            'SELECT id, spreadsheet_id, organization_id, acting_player_id, timezone, country_code, added_at, last_fetched_at, last_fetch_error
             FROM event_results_sheet
             WHERE CAST(:sheetId AS UUID) IS NULL OR id = CAST(:sheetId AS UUID)
             ORDER BY added_at, id',
            ['sheetId' => $sheetId],
        );

        if ($sheets === []) {
            return [];
        }

        /** @var list<array{sheet_id: string, gid: string, title: string, status: string, problems: string, competition_id: null|string, first_imported_at: null|string, applied_at: null|string, results: int|string}> $tabs */
        $tabs = $this->database->fetchAllAssociative(
            'SELECT tab.sheet_id, tab.gid, tab.title, tab.status, tab.problems, tab.competition_id, tab.first_imported_at, tab.applied_at,
                (SELECT COUNT(*) FROM event_imported_result result WHERE result.tab_id = tab.id) AS results
             FROM event_results_sheet_tab tab
             WHERE tab.sheet_id IN (:sheetIds)
             ORDER BY tab.title, tab.gid',
            ['sheetIds' => array_column($sheets, 'id')],
            ['sheetIds' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $tabsBySheet = [];

        foreach ($tabs as $tab) {
            $tabsBySheet[$tab['sheet_id']][] = [
                'gid' => $tab['gid'],
                'title' => $tab['title'],
                'status' => $tab['status'],
                'problems' => Json::decode($tab['problems'], forceArrays: true),
                'competitionId' => $tab['competition_id'],
                'results' => (int) $tab['results'],
                'firstImportedAt' => $tab['first_imported_at'],
                'appliedAt' => $tab['applied_at'],
            ];
        }

        return array_map(static fn (array $sheet): array => [
            'id' => $sheet['id'],
            'spreadsheetId' => $sheet['spreadsheet_id'],
            'organizationId' => $sheet['organization_id'],
            'actingPlayerId' => $sheet['acting_player_id'],
            'timezone' => $sheet['timezone'],
            'countryCode' => $sheet['country_code'],
            'addedAt' => $sheet['added_at'],
            'lastFetchedAt' => $sheet['last_fetched_at'],
            'lastFetchError' => $sheet['last_fetch_error'],
            'tabs' => $tabsBySheet[$sheet['id']] ?? [],
        ], $sheets);
    }
}
