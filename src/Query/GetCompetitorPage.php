<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitorPageNotFound;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\CompetitorPageEntry;
use SpeedPuzzling\Web\Results\CompetitorPageRound;
use SpeedPuzzling\Web\Results\CompetitorPageView;
use SpeedPuzzling\Web\Results\OfficialRoundEntrants;
use SpeedPuzzling\Web\Results\PublishedEntryPuzzle;
use SpeedPuzzling\Web\Results\PublishedRoundEntrant;
use SpeedPuzzling\Web\Results\RoundPuzzlesNow;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Results\RoundStopwatchState;
use SpeedPuzzling\Web\Results\TimeProposalView;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use SpeedPuzzling\Web\Value\CompetitionTag;
use SpeedPuzzling\Web\Value\CompetitionTagKind;
use SpeedPuzzling\Web\Value\CompetitorResultState;
use SpeedPuzzling\Web\Value\EventTime;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The public competitor page of a scanned competition tag (docs/features/competitions-management/competitor-pages.md,
 * toolkit B-qr-competitor-page.md §4, R-review-and-plan.md §2.3) - a person, a pair/team line-up, a table or one
 * round's entry, with their rounds:
 *
 * - **Only while the event has competitor pages on** - else CompetitorPageNotFound, for organisers too, so nothing
 *   public exists by accident. A draft comes back marked (isDraft): the page answers 404 to anybody but its team.
 * - Which entries a tag stands for follows CompetitionTagResolver / GetCompetitionTagEntries (B2): a person's entry in
 *   every round they are in (solo: theirs; pair/team: their pair/team; no pair/team yet: themselves), a line-up's
 *   pairs/teams by their exact member set (CompetitionLineupId), whoever sits at the table in each round, one entry. The
 *   current round is LiveResultsCurrentRound's pick among the tag's rounds - a table page shows it first.
 * - People are the organiser's names, masked like the public round page (OfficialEntrantsView::entrant()); the blocklist
 *   (OfficialEntrantsView::isHiddenFromViewer(), the group rule): a person, line-up or entry hidden from the viewer is
 *   CompetitorPageNotFound; a table page and a person's pair/team round keep the round but mask its entry.
 * - Puzzles by the public reveal rule only (RoundPuzzlesVisibleNow) - for every viewer, organisers included.
 * - Results: a published round's official result and place come from OfficialEntrantsView (the round page's rows and
 *   ranks, never computed here); before publishing a confirmed result shows only when the organiser chose it
 *   (competitor_pages_live_results) or to the competitor themself - never a place.
 *
 * Statements: the competition with its rounds (1), the tag's entries with their people (1), the round puzzles (1), the
 * published rounds' official entries (0-2: OfficialEntrantsView runs one per category of rounds it is asked for), the
 * signed-in competitor's own time proposals (0-1: only with time proposals on and an own entry shown - B8).
 *
 * @phpstan-type RoundRow array{id: string, name: string, category: RoundCategory, starts_at: DateTimeImmutable, zone: string, zone_assumed: bool, minutes_limit: null|int, stopwatch_status: null|string, stopwatch_started_at: null|DateTimeImmutable, stopwatch_stopped_at: null|DateTimeImmutable, published: bool, table_numbers_off: bool, puzzle_format: string, puzzles_to_solve: null|int}
 * @phpstan-type EntryRow array{kind: string, id: string, round_id: null|string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, puzzles: null|RoundEntryPuzzles, team_name: null|string, entrants: list<PublishedRoundEntrant>}
 */
readonly final class GetCompetitorPage
{
    public function __construct(
        private Connection $database,
        private OfficialEntrantsView $officialEntrantsView,
        private RoundPuzzlesVisibleNow $roundPuzzlesVisibleNow,
        private PrivateProfileAccess $privateProfileAccess,
        private GetTimeProposals $getTimeProposals,
    ) {
    }

    /**
     * @throws CompetitorPageNotFound
     */
    public function of(string $competitionId, CompetitionTag $tag, null|string $viewerPlayerId, DateTimeImmutable $now): CompetitorPageView
    {
        if (Uuid::isValid($competitionId) === false || ($tag->kind !== CompetitionTagKind::Table && Uuid::isValid($tag->id) === false)) {
            throw new CompetitorPageNotFound();
        }

        $competitionId = strtolower($competitionId);
        $viewerPlayerId = $viewerPlayerId !== null ? strtolower($viewerPlayerId) : null;
        $competition = $this->competition($competitionId);

        if ($competition === null || $competition['competitor_pages'] === false) {
            throw new CompetitorPageNotFound();
        }

        /** @var array<string, RoundRow> $rounds */
        $rounds = $competition['rounds'];
        $rows = $this->entries($competitionId, $tag, $viewerPlayerId);

        // The header: a person page's participant (in no round at all too), else the entrant of the latest round
        $header = null;
        $entryRows = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'participant') {
                $header = $row;
            } elseif ($row['round_id'] !== null && isset($rounds[$row['round_id']])) {
                $entryRows[] = $row;
            }
        }

        $known = match ($tag->kind) {
            CompetitionTagKind::Participant => $header !== null,
            CompetitionTagKind::Table => true,
            default => $entryRows !== [],
        };

        if ($known === false) {
            throw new CompetitorPageNotFound();
        }

        // Rounds in schedule order (the competition statement's order)
        $roundOrder = array_flip(array_keys($rounds));
        usort($entryRows, static fn (array $a, array $b): int => $roundOrder[(string) $a['round_id']] <=> $roundOrder[(string) $b['round_id']]);

        $headerRow = $header ?? ($tag->kind !== CompetitionTagKind::Table ? $entryRows[count($entryRows) - 1] : null);
        $headerEntrants = $headerRow !== null ? $headerRow['entrants'] : [];

        // The blocklist: a page about somebody hidden from the viewer is not there for them (a table is nobody's page)
        if ($tag->kind !== CompetitionTagKind::Table) {
            foreach ($header !== null ? [$header] : $entryRows as $row) {
                if ($this->officialEntrantsView->isHiddenFromViewer($row['entrants'], $viewerPlayerId)) {
                    throw new CompetitorPageNotFound();
                }
            }
        }

        // The current round among the tag's rounds - first on a table page
        $tagRoundIds = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['round_id'], $entryRows)));
        $current = $tag->kind === CompetitionTagKind::Entry
            ? ($tagRoundIds[0] ?? null)
            : LiveResultsCurrentRound::pick(array_map(
                static fn (string $roundId): RoundResultsOverview => self::overview($rounds[$roundId], $now),
                $tagRoundIds,
            ), $now)?->roundId;

        if ($tag->kind === CompetitionTagKind::Table && $current !== null) {
            usort($entryRows, static fn (array $a, array $b): int => ($b['round_id'] === $current) <=> ($a['round_id'] === $current));
        }

        $masked = [];
        foreach ($entryRows as $index => $row) {
            $masked[$index] = $this->officialEntrantsView->isHiddenFromViewer($row['entrants'], $viewerPlayerId);
        }

        $puzzles = $this->roundPuzzlesVisibleNow->forRounds($tagRoundIds, $now);

        // Official places of the published rounds with an entry the viewer may see - OfficialEntrantsView's rows
        $published = [];
        foreach ($entryRows as $index => $row) {
            $round = $rounds[(string) $row['round_id']];

            if ($round['published'] && $masked[$index] === false && $row['kind'] !== 'unpaired') {
                $published[$round['id']] = $round['category'];
            }
        }
        $official = $published !== [] ? $this->officialEntrantsView->forRounds($published, $viewerPlayerId) : [];

        // Time proposals (B8): the viewer's own entries get their latest proposal (one statement, only then)
        $timeProposals = $competition['time_proposals'];
        $ownRefs = [];
        foreach ($entryRows as $index => $row) {
            if ($timeProposals && $masked[$index] === false && in_array($row['kind'], ['participant_round', 'team'], true) && OfficialEntrantsView::isViewers($row['entrants'], $viewerPlayerId)) {
                $ownRefs[] = $row['kind'] === 'team' ? RoundEntryRef::team($row['id']) : RoundEntryRef::participantRound($row['id']);
            }
        }
        $proposals = $ownRefs !== [] ? $this->getTimeProposals->latestOfEntries($ownRefs) : [];

        $pageRounds = [];
        foreach ($entryRows as $index => $row) {
            $round = $rounds[(string) $row['round_id']];
            $pageRounds[] = $this->round(
                $round,
                $row,
                $masked[$index],
                $round['id'] === $current,
                $puzzles[$round['id']] ?? new RoundPuzzlesNow([], null),
                $official[$round['id']] ?? null,
                $competition['competitor_pages_live_results'],
                $viewerPlayerId,
                $now,
                $timeProposals,
                $proposals,
            );
        }

        $viewerIsEntrant = $tag->kind !== CompetitionTagKind::Table && $viewerPlayerId !== null && (
            OfficialEntrantsView::isViewers($headerEntrants, $viewerPlayerId)
            || array_any($entryRows, static fn (array $row): bool => OfficialEntrantsView::isViewers($row['entrants'], $viewerPlayerId))
        );

        return new CompetitorPageView(
            competitionId: $competitionId,
            competition: new CompetitionReference(
                name: $competition['name'],
                slug: $competition['slug'],
                seriesName: $competition['series_name'],
                seriesSlug: $competition['series_slug'],
            ),
            kind: $tag->kind,
            tagId: $tag->id,
            isTeam: $headerRow !== null && $headerRow['kind'] === 'team',
            teamName: $headerRow !== null && $headerRow['kind'] === 'team' ? $this->latestTeamName($entryRows) : null,
            entrants: $headerEntrants,
            viewerIsEntrant: $viewerIsEntrant,
            liveResults: $competition['competitor_pages_live_results'],
            isDraft: $competition['is_draft'],
            rounds: $pageRounds,
            serverNow: $now,
            timeProposals: $timeProposals,
        );
    }

    /**
     * @param RoundRow $round
     * @param EntryRow $row
     * @param array<string, TimeProposalView> $proposals the viewer's own latest proposals by entry ref
     */
    private function round(
        array $round,
        array $row,
        bool $masked,
        bool $current,
        RoundPuzzlesNow $puzzles,
        null|OfficialRoundEntrants $official,
        bool $liveResults,
        null|string $viewerPlayerId,
        DateTimeImmutable $now,
        bool $timeProposals,
        array $proposals,
    ): CompetitorPageRound {
        $isPick = $round['puzzle_format'] === 'pick';
        $entry = $masked ? null : $this->entry($round, $row, $puzzles, $official, $liveResults, $viewerPlayerId);

        // Who may tap Stop here: the signed-in competitor on their own entry (the server checks again); a guest on a
        // page whose entrant has a linked profile is told to sign in - nothing says who
        $canPropose = $timeProposals && $entry !== null && $entry->unpaired === false && $entry->isViewers;
        $signInToPropose = $timeProposals
            && $viewerPlayerId === null
            && $entry !== null
            && $entry->unpaired === false
            && $round['published'] === false
            && $entry->result === null
            && array_any($entry->entrants, static fn (PublishedRoundEntrant $entrant): bool => $entrant->linkedPlayerId !== null);

        return new CompetitorPageRound(
            roundId: $round['id'],
            name: $round['name'],
            category: $round['category'],
            startsAt: new EventTime($round['starts_at'], $round['zone'], $round['zone_assumed']),
            minutesLimit: $round['minutes_limit'],
            tableNumber: $round['table_numbers_off'] ? null : $row['table_number'],
            current: $current,
            stopwatch: new RoundStopwatchState(
                roundId: $round['id'],
                storedStatus: $round['stopwatch_status'],
                startedAt: $round['stopwatch_started_at'],
                stoppedAt: $round['stopwatch_stopped_at'],
                minutesLimit: $round['minutes_limit'] ?? 0,
                asOf: $now,
            ),
            published: $round['published'],
            isPick: $isPick,
            puzzlesToSolve: $isPick ? $round['puzzles_to_solve'] : null,
            slots: $isPick ? $puzzles->slots : [],
            puzzles: $puzzles->puzzles,
            nextRevealAtMs: $puzzles->nextRevealAtMs,
            entry: $entry,
            masked: $masked,
            canPropose: $canPropose,
            proposal: $canPropose ? ($proposals[$entry->ref->toString()] ?? null)?->forCompetitor() : null,
            signInToPropose: $signInToPropose,
        );
    }

    /**
     * @param RoundRow $round
     * @param EntryRow $row
     */
    private function entry(
        array $round,
        array $row,
        RoundPuzzlesNow $puzzles,
        null|OfficialRoundEntrants $official,
        bool $liveResults,
        null|string $viewerPlayerId,
    ): CompetitorPageEntry {
        $ref = $row['kind'] === 'team' ? RoundEntryRef::team($row['id']) : RoundEntryRef::participantRound($row['id']);
        $isViewers = OfficialEntrantsView::isViewers($row['entrants'], $viewerPlayerId);
        $ownResult = RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']);

        $result = null;
        $state = null;
        $rank = null;
        $qualified = false;
        $record = null;

        if ($row['kind'] === 'unpaired') {
            // In a pair/team round without a pair/team yet - nothing recorded for them alone
        } elseif ($round['published']) {
            $publishedEntry = null;
            foreach ($official->entries ?? [] as $candidate) {
                if ($candidate->ref->toString() === $ref->toString()) {
                    $publishedEntry = $candidate;
                    break;
                }
            }

            if ($publishedEntry !== null) {
                $result = $publishedEntry->result;
                $state = CompetitorResultState::Official;
                $rank = $publishedEntry->rank;
                $qualified = $publishedEntry->qualified;
                $record = $publishedEntry->puzzles;
            } elseif ($ownResult->didNotStart) {
                // Not ranked, so not among the round page's rows - but as official as the rest
                $result = $ownResult;
                $state = CompetitorResultState::Official;
            }
        } elseif ($ownResult->isNone() === false && ($liveResults || $isViewers)) {
            $result = $ownResult;
            $state = $liveResults ? CompetitorResultState::Live : CompetitorResultState::Own;
            $record = $row['puzzles'];
        }

        $isPick = $round['puzzle_format'] === 'pick';
        $chips = $result !== null && $isPick ? PublishedEntryPuzzle::listOf($record, $puzzles->slots) : [];

        return new CompetitorPageEntry(
            ref: $ref,
            isTeam: $row['kind'] === 'team',
            teamName: $row['team_name'],
            entrants: $row['entrants'],
            isViewers: $isViewers,
            result: $result,
            resultState: $state,
            rank: $rank,
            qualified: $qualified,
            puzzleChips: $chips,
            piecesCount: $result !== null && $result->isUnfinished() ? self::piecesCount($isPick, $round['puzzles_to_solve'], $chips, $puzzles) : null,
            unpaired: $row['kind'] === 'unpaired',
        );
    }

    /**
     * The pieces an unfinished result is out of, when the public may know them: a one-result round's one puzzle, or all
     * K puzzles a pick entry chose - each shown to everybody.
     *
     * @param list<PublishedEntryPuzzle> $chips
     */
    private static function piecesCount(bool $isPick, null|int $toSolve, array $chips, RoundPuzzlesNow $puzzles): null|int
    {
        if ($isPick === false) {
            return count($puzzles->slots) === 1 && count($puzzles->puzzles) === 1 ? $puzzles->puzzles[0]->piecesCount : null;
        }

        if ($toSolve === null || count($chips) !== $toSolve) {
            return null;
        }

        $pieces = 0;
        foreach ($chips as $chip) {
            if ($chip->puzzle === null) {
                return null;
            }

            $pieces += $chip->puzzle->piecesCount;
        }

        return $pieces;
    }

    /**
     * @param list<EntryRow> $rows
     */
    private function latestTeamName(array $rows): null|string
    {
        $name = null;
        foreach ($rows as $row) {
            if ($row['team_name'] !== null && trim($row['team_name']) !== '') {
                $name = $row['team_name'];
            }
        }

        return $name;
    }

    /**
     * The competition with its competitor page settings and its rounds in schedule order - one statement.
     *
     * @return null|array{name: string, slug: null|string, series_name: null|string, series_slug: null|string, is_draft: bool, competitor_pages: bool, competitor_pages_live_results: bool, time_proposals: bool, rounds: array<string, RoundRow>}
     */
    private function competition(string $competitionId): null|array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    c.name,
    c.slug,
    c.competitor_pages,
    c.competitor_pages_live_results,
    (c.competitor_pages AND c.time_proposals AND NOT c.is_online) AS time_proposals,
    (c.is_draft OR COALESCE(cs.is_draft, false)) AS is_draft,
    cs.name AS series_name,
    cs.slug AS series_slug,
    c.location_country_code,
    cs.location_country_code AS series_country_code,
    cr.id AS round_id,
    cr.name AS round_name,
    cr.category,
    cr.starts_at,
    cr.timezone,
    cr.minutes_limit,
    cr.stopwatch_status,
    cr.stopwatch_started_at,
    cr.stopwatch_stopped_at,
    cr.results_published_at IS NOT NULL AS published,
    cr.table_numbers_off,
    cr.puzzle_format,
    cr.puzzles_to_solve
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
LEFT JOIN competition_round cr ON cr.competition_id = c.id
WHERE c.id = :competitionId
ORDER BY cr.starts_at, cr.name, cr.id
SQL,
            ['competitionId' => $competitionId],
        );

        if ($rows === []) {
            return null;
        }

        /** @var array{name: string, slug: null|string, competitor_pages: bool, competitor_pages_live_results: bool, time_proposals: bool, is_draft: bool, series_name: null|string, series_slug: null|string, location_country_code: null|string, series_country_code: null|string} $first */
        $first = $rows[0];
        $rounds = [];

        foreach ($rows as $row) {
            /** @var array{round_id: null|string, round_name: string, category: string, starts_at: string, timezone: null|string, minutes_limit: null|int, stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_stopped_at: null|string, published: bool, table_numbers_off: bool, puzzle_format: string, puzzles_to_solve: null|int, location_country_code: null|string, series_country_code: null|string} $row */
            if ($row['round_id'] === null) {
                continue;
            }

            $roundId = strtolower($row['round_id']);
            $rounds[$roundId] = [
                'id' => $roundId,
                'name' => $row['round_name'],
                'category' => RoundCategory::from($row['category']),
                'starts_at' => new DateTimeImmutable($row['starts_at']),
                'zone' => RoundTimezone::resolve($row['timezone'], $row['location_country_code'], $row['series_country_code']),
                'zone_assumed' => RoundTimezone::isAssumed($row['timezone'], $row['location_country_code'], $row['series_country_code']),
                'minutes_limit' => $row['minutes_limit'],
                'stopwatch_status' => $row['stopwatch_status'],
                'stopwatch_started_at' => $row['stopwatch_started_at'] !== null ? new DateTimeImmutable($row['stopwatch_started_at']) : null,
                'stopwatch_stopped_at' => $row['stopwatch_stopped_at'] !== null ? new DateTimeImmutable($row['stopwatch_stopped_at']) : null,
                'published' => $row['published'],
                'table_numbers_off' => $row['table_numbers_off'],
                'puzzle_format' => $row['puzzle_format'],
                'puzzles_to_solve' => $row['puzzles_to_solve'],
            ];
        }

        return [
            'name' => $first['name'],
            'slug' => $first['slug'],
            'series_name' => $first['series_name'],
            'series_slug' => $first['series_slug'],
            'is_draft' => $first['is_draft'],
            'competitor_pages' => $first['competitor_pages'],
            'competitor_pages_live_results' => $first['competitor_pages_live_results'],
            'time_proposals' => $first['time_proposals'],
            'rounds' => $rounds,
        ];
    }

    /**
     * The tag's entries in every round with their people - one statement (GetCompetitionTagEntries' rules, with names):
     * solo entries (`participant_round`), a person of a pair/team round without a pair/team yet (`unpaired`, person
     * pages only), pairs/teams (`team`) and, for a person page, the participant itself (`participant`, the header even
     * in no round). People removed from the event are nobody's.
     *
     * @return list<EntryRow>
     */
    private function entries(string $competitionId, CompetitionTag $tag, null|string $viewerPlayerId): array
    {
        $private = $this->privateProfileAccess->sqlIsPrivate('player');
        $member = <<<SQL
json_build_object(
    'participant_id', cp.id,
    'name', cp.name,
    'country', cp.country,
    'player_id', player.id,
    'player_code', player.code,
    'player_country', player.country,
    'player_avatar', player.avatar,
    'player_is_private', {$private}
)
SQL;
        $going = CompetitionParticipantGoing::sql('cp');

        [$soloFilter, $teamFilter, $participantPart] = match ($tag->kind) {
            CompetitionTagKind::Participant => [
                "cpr.participant_id = :tagId AND (cr.category = 'solo' OR cpr.team_id IS NULL)",
                'EXISTS (SELECT 1 FROM competition_participant_round own WHERE own.team_id = ct.id AND own.participant_id = :tagId)',
                true,
            ],
            CompetitionTagKind::Lineup => ['false', 'true', false],
            CompetitionTagKind::Table => [
                "cr.category = 'solo' AND cpr.table_number = :tagTable AND {$going}",
                'ct.table_number = :tagTable',
                false,
            ],
            // A pair/team member's own row stands for their pair/team (GetCompetitionTagEntries::entry())
            CompetitionTagKind::Entry => [
                "cpr.id = :tagId AND cr.category = 'solo'",
                '(ct.id = :tagId OR ct.id = (SELECT own.team_id FROM competition_participant_round own WHERE own.id = :tagId))',
                false,
            ],
        };

        $participantSql = $participantPart ? <<<SQL
UNION ALL
SELECT
    'participant' AS kind,
    cp.id,
    NULL::uuid AS round_id,
    NULL::int AS table_number,
    NULL::int AS result_seconds,
    NULL::int AS result_pieces_placed,
    false AS result_did_not_start,
    NULL::text AS result_puzzles,
    NULL::varchar AS team_name,
    json_build_array({$member})::text AS members
FROM competition_participant cp
LEFT JOIN player ON player.id = cp.player_id
WHERE cp.id = :tagId
    AND cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
SQL : '';

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    CASE WHEN cr.category = 'solo' THEN 'participant_round' ELSE 'unpaired' END AS kind,
    cpr.id,
    cpr.round_id,
    cpr.table_number,
    cpr.result_seconds,
    cpr.result_pieces_placed,
    cpr.result_did_not_start,
    cpr.result_puzzles::text AS result_puzzles,
    NULL::varchar AS team_name,
    json_build_array({$member})::text AS members
FROM competition_participant_round cpr
INNER JOIN competition_round cr ON cr.id = cpr.round_id
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
LEFT JOIN player ON player.id = cp.player_id
WHERE cr.competition_id = :competitionId
    AND {$soloFilter}
UNION ALL
SELECT
    'team' AS kind,
    ct.id,
    ct.round_id,
    ct.table_number,
    ct.result_seconds,
    ct.result_pieces_placed,
    ct.result_did_not_start,
    ct.result_puzzles::text AS result_puzzles,
    ct.name AS team_name,
    (
        SELECT json_agg({$member} ORDER BY cp.name, cp.id)
        FROM competition_participant_round m
        INNER JOIN competition_participant cp ON cp.id = m.participant_id AND cp.deleted_at IS NULL
        LEFT JOIN player ON player.id = cp.player_id
        WHERE m.team_id = ct.id
    )::text AS members
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id AND cr.category <> 'solo'
WHERE cr.competition_id = :competitionId
    AND {$teamFilter}
{$participantSql}
SQL,
            [
                'competitionId' => $competitionId,
                'tagId' => $tag->kind === CompetitionTagKind::Table ? null : $tag->id,
                'tagTable' => $tag->tableNumber(),
            ],
        );

        $entries = [];

        foreach ($rows as $row) {
            /** @var array{kind: string, id: string, round_id: null|string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_puzzles: null|string, team_name: null|string, members: null|string} $row */
            /** @var list<array{participant_id: string, name: string, country: null|string, player_id: null|string, player_code: null|string, player_country: null|string, player_avatar: null|string, player_is_private: null|bool}> $members */
            $members = $row['members'] !== null ? json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR) : [];

            // A line-up is its exact member set - hashed here, never stored (CompetitionLineupId)
            if ($tag->kind === CompetitionTagKind::Lineup && ($members === [] || CompetitionLineupId::of($competitionId, array_column($members, 'participant_id')) !== $tag->id)) {
                continue;
            }

            $entries[] = [
                'kind' => $row['kind'],
                'id' => strtolower($row['id']),
                'round_id' => $row['round_id'] !== null ? strtolower($row['round_id']) : null,
                'table_number' => $row['table_number'],
                'result_seconds' => $row['result_seconds'],
                'result_pieces_placed' => $row['result_pieces_placed'],
                'result_did_not_start' => $row['result_did_not_start'],
                'puzzles' => RoundEntryPuzzles::fromStored($row['result_puzzles']),
                'team_name' => $row['team_name'],
                'entrants' => array_map(
                    static fn (array $member): PublishedRoundEntrant => OfficialEntrantsView::entrant(
                        $member['name'],
                        $member['country'],
                        $member['player_id'],
                        $member['player_code'],
                        $member['player_country'],
                        $member['player_avatar'],
                        $member['player_is_private'] === true,
                        $viewerPlayerId,
                    ),
                    $members,
                ),
            ];
        }

        return $entries;
    }

    /**
     * Just what LiveResultsCurrentRound reads (when the round runs, its stopwatch) - the one rule of "the current
     * round" for the scan landing and these pages; the counts of the organiser's overview are not needed here.
     *
     * @param RoundRow $round
     */
    private static function overview(array $round, DateTimeImmutable $now): RoundResultsOverview
    {
        return new RoundResultsOverview(
            roundId: $round['id'],
            name: $round['name'],
            category: $round['category']->value,
            startsAt: $round['starts_at'],
            minutesLimit: $round['minutes_limit'],
            slug: null,
            stopwatchStatus: RoundStopwatchStatus::at($round['stopwatch_status'], $round['stopwatch_started_at'], $now),
            stopwatchStartedAt: $round['stopwatch_started_at'],
            stopwatchStoppedAt: $round['stopwatch_stopped_at'],
            resultsPublishedAt: null,
            resultsFirstPublishedAt: null,
            tableNumbersOff: $round['table_numbers_off'],
            puzzlesCount: 0,
            piecesCount: null,
            entriesTotal: 0,
            entriesWithTableNumber: 0,
            entriesWithResult: 0,
            entriesQualified: 0,
        );
    }
}
