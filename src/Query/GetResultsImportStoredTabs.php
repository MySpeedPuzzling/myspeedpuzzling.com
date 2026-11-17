<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ResultsImport\StoredResultsTab;
use SpeedPuzzling\Web\Results\ResultsImport\StoredTeamResult;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * What is stored of every event of a results source - its tab, the event made of it (drafts included: the
 * organization's team sees its own events on its results import page), the round the import keeps up to date (the
 * first by start, ApplyEventResultsTabHandler) and every team's result. Two statements for the whole source. Team names
 * only, never a person: a claimed result is just "claimed".
 */
readonly final class GetResultsImportStoredTabs
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<array-key, StoredResultsTab> by the event's stable key (a numeric key is an int key in PHP)
     */
    public function ofSource(string $sourceId): array
    {
        if (Uuid::isValid($sourceId) === false) {
            return [];
        }

        $tabsQuery = <<<SQL
SELECT t.id AS tab_id, t.gid, t.status, t.content_hash, t.first_imported_at, t.applied_at, t.competition_id,
    c.name AS competition_name, c.slug AS competition_slug, c.location, c.date_from,
    r.id AS round_id, r.slug AS round_slug, r.category, r.minutes_limit, r.starts_at, r.start_time_known, r.timezone,
    (SELECT COALESCE(json_agg(LOWER(crp.puzzle_id::text)), '[]') FROM competition_round_puzzle crp WHERE crp.round_id = r.id) AS round_puzzle_ids
FROM event_results_sheet_tab t
LEFT JOIN competition c ON c.id = t.competition_id
LEFT JOIN LATERAL (
    SELECT cr.* FROM competition_round cr WHERE cr.competition_id = c.id ORDER BY cr.starts_at, cr.id LIMIT 1
) r ON true
WHERE t.sheet_id = :sourceId
SQL;

        // The team's result: claimed once somebody holds a spot - its tracker or a registered member of its people
        // (PuzzleSolvingTime::isClaimedByAnybody())
        $resultsQuery = <<<SQL
-- A single person (no pair/team) is named by the sheet on the imported row (solo-and-relays.md section 1)
SELECT eir.tab_id, eir.team_key, COALESCE(pt.name, eir.name) AS team_name, pst.seconds_to_solve, pst.pieces_placed, pst.puzzlers_count,
    (pst.player_id IS NOT NULL OR EXISTS (
        SELECT 1 FROM json_array_elements(pst.team -> 'puzzlers') AS member(player) WHERE member.player ->> 'player_id' IS NOT NULL
    )) AS claimed
FROM event_imported_result eir
INNER JOIN event_results_sheet_tab t ON t.id = eir.tab_id
INNER JOIN puzzle_solving_time pst ON pst.id = eir.solving_time_id
LEFT JOIN puzzling_team pt ON pt.id = pst.puzzling_team_id
WHERE t.sheet_id = :sourceId
ORDER BY eir.team_key
SQL;

        /** @var list<array{tab_id: string, team_key: string, team_name: null|string, seconds_to_solve: null|int|string, pieces_placed: null|int|string, puzzlers_count: int|string, claimed: bool}> $resultRows */
        $resultRows = $this->database->executeQuery($resultsQuery, ['sourceId' => $sourceId])->fetchAllAssociative();

        /** @var array<string, array<array-key, StoredTeamResult>> $resultsByTab */
        $resultsByTab = [];

        foreach ($resultRows as $row) {
            $resultsByTab[$row['tab_id']][$row['team_key']] = new StoredTeamResult(
                teamKey: $row['team_key'],
                teamName: $row['team_name'],
                seconds: $row['seconds_to_solve'] !== null ? (int) $row['seconds_to_solve'] : null,
                piecesPlaced: $row['pieces_placed'] !== null ? (int) $row['pieces_placed'] : null,
                teamSize: (int) $row['puzzlers_count'],
                claimed: $row['claimed'],
            );
        }

        /** @var list<array{tab_id: string, gid: string, status: string, content_hash: null|string, first_imported_at: null|string, applied_at: null|string, competition_id: null|string, competition_name: null|string, competition_slug: null|string, location: null|string, date_from: null|string, round_id: null|string, round_slug: null|string, category: null|string, minutes_limit: null|int|string, starts_at: null|string, start_time_known: null|bool, timezone: null|string, round_puzzle_ids: string}> $tabRows */
        $tabRows = $this->database->executeQuery($tabsQuery, ['sourceId' => $sourceId])->fetchAllAssociative();

        $tabs = [];

        foreach ($tabRows as $row) {
            /** @var list<string> $roundPuzzleIds */
            $roundPuzzleIds = Json::decode($row['round_puzzle_ids'], forceArrays: true);

            $tabs[$row['gid']] = new StoredResultsTab(
                key: $row['gid'],
                status: EventTabStatus::from($row['status']),
                contentHash: $row['content_hash'],
                isDetached: $row['first_imported_at'] !== null && $row['competition_id'] === null,
                appliedAt: $row['applied_at'] !== null ? new DateTimeImmutable($row['applied_at']) : null,
                competitionId: $row['competition_id'],
                competitionName: $row['competition_name'],
                competitionSlug: $row['competition_slug'],
                location: $row['location'],
                dateFrom: $row['date_from'] !== null ? new DateTimeImmutable($row['date_from']) : null,
                roundId: $row['round_id'],
                roundSlug: $row['round_slug'],
                roundCategory: $row['category'] !== null ? RoundCategory::from($row['category']) : null,
                minutesLimit: $row['minutes_limit'] !== null ? (int) $row['minutes_limit'] : null,
                roundStartsAt: $row['starts_at'] !== null ? new DateTimeImmutable($row['starts_at'], new DateTimeZone('UTC')) : null,
                startTimeKnown: $row['start_time_known'] ?? true,
                roundTimezone: $row['timezone'],
                roundPuzzleIds: $roundPuzzleIds,
                results: $resultsByTab[$row['tab_id']] ?? [],
            );
        }

        return $tabs;
    }
}
