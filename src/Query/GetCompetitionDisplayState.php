<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Results\CompetitionDisplayState;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\DisplayResultRow;
use SpeedPuzzling\Web\Results\DisplayResults;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Results\RoundStopwatchState;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayImages;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayStandings;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Services\UploaderHelper;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\OnlineToken;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * What a competition display shows now, found by its token (docs/features/competitions-management/toolkit/
 * D-displays-overlays.md §7.2, §9.3, §9.4; R-review-and-plan.md §1.5, §2.2, §2.3) - the read side of the timer screens
 * and stream overlays. The token is the capability: no viewer, no session.
 *
 * - **Round**: pinned (a deleted pinned round falls back to current), else the current one by LiveResultsCurrentRound
 *   among the chosen rounds that still exist (all rounds when none of them does).
 * - **Stopwatch**: RoundStopwatchState read from the same row at the same moment - the payload of the public
 *   `/round-stopwatch/{roundId}` topic and state endpoint, so the display counts exactly like the projector page.
 * - **Puzzles**: only RoundPuzzlesVisibleNow (the public rule for every viewer: the round's reveal incl. the stopwatch
 *   mode, image-only hiding, the site-wide embargo). Nothing of a hidden puzzle - not its name, picture, label,
 *   position or the count; pick rounds' slots are never sent.
 * - **Results**: only for a design with a shown results element (DisplayDesignSanitizer::resultsDemand()), and per
 *   element type only what an element of it shows: everything once the round's results are published, before that
 *   only for a type with an element the organiser explicitly switched to `source: live`. Names are the organiser's
 *   participant / team names; a linked player's country only when their profile is public - the published round page
 *   for a guest. Displays have no viewer, so the blocklist does not apply (BlocklistQueryCoverageTest). Did not start is
 *   left out, people removed from the event and the waitlist too (CompetitionParticipantGoing).
 * - **Drafts**: a display works for a draft event (a rehearsal before publishing - the token is the organiser's), it is
 *   never listed anywhere (DraftVisibilityCoverageTest).
 *
 * - **Standings** (v2 S2): for a shown `standings` element the published standing as a guest sees it (DisplayStandings,
 *   cached per generation of the event's standings - 0 statements warm).
 *
 * Three statements (display + event, rounds, visible puzzles), a fourth for the results entries only when the design
 * shows results that may be shown now.
 *
 * @phpstan-type Round array{id: string, name: string, slug: null|string, category: string, starts_at: DateTimeImmutable, minutes_limit: null|int, timezone: null|string, badge_bg: string, stopwatch_status: null|string, stopwatch_started_at: null|DateTimeImmutable, stopwatch_stopped_at: null|DateTimeImmutable, results_published_at: null|DateTimeImmutable}
 * @phpstan-type Display array{id: string, competition_id: string, token: string, kind: string, locale: string, design: array<string, mixed>, design_version: int, follow_mode: string, pinned_round_id: null|string, follow_round_ids: null|list<string>, uses_results: bool, uses_live_results: bool, competition_name: string, competition_slug: null|string, competition_logo: null|string, location: null|string, location_country_code: null|string, date_from: null|string, date_to: null|string, series_logo: null|string, series_name: null|string, series_slug: null|string, series_country_code: null|string, organization_logo: null|string}
 */
readonly final class GetCompetitionDisplayState
{
    private const string PUZZLE_IMAGE_PRESET = 'puzzle_medium';

    public function __construct(
        private Connection $database,
        private RoundPuzzlesVisibleNow $roundPuzzlesVisibleNow,
        private UploaderHelper $uploaderHelper,
        private DisplayImages $displayImages,
        private ClockInterface $clock,
        private string $nginxProxyBaseUrl,
        private DisplayStandings $displayStandings,
    ) {
    }

    /**
     * @throws CompetitionDisplayNotFound for an unknown (or regenerated) token
     */
    public function forToken(string $token): CompetitionDisplayState
    {
        $display = $this->display($token);
        $now = $this->clock->now();
        $rounds = $this->rounds($display['competition_id']);

        [$round, $followed] = self::followedRound($display, $rounds, $now);
        $roundId = $round['id'] ?? null;

        $puzzles = [];
        $anyRevealed = false;
        $refreshAtMs = $display['follow_mode'] === DisplayFollowMode::Pinned->value && $round !== null && $round['id'] === $display['pinned_round_id']
            ? null
            : self::nextPickChangeMs($followed, $now);

        if ($roundId !== null) {
            $visible = $this->roundPuzzlesVisibleNow->forRound($roundId, $now);
            $anyRevealed = $visible->anyRevealed();

            foreach ($visible->puzzles as $puzzle) {
                $puzzles[] = [
                    'name' => $puzzle->puzzleName,
                    'brand' => $puzzle->manufacturerName,
                    'pieces' => $puzzle->piecesCount,
                    'imageUrl' => $puzzle->puzzleImage !== null ? $this->thumbnail($puzzle->puzzleImage, self::PUZZLE_IMAGE_PRESET) : null,
                ];
            }

            if ($visible->nextRevealAtMs !== null) {
                $refreshAtMs = $refreshAtMs === null ? $visible->nextRevealAtMs : min($refreshAtMs, $visible->nextRevealAtMs);
            }
        }

        $design = $display['design'];
        $results = null;

        if ($round !== null && $display['uses_results']) {
            $results = $this->results($round, $design, $display['uses_live_results'], $display['token']);
        }

        // Published standings (v2 S2) - guest snapshots, cached per generation; a failure is an empty element, never a
        // failed state (DisplayStandings catches everything)
        $standings = $this->displayStandings->forDisplay(
            $design,
            $display['competition_id'],
            $round === null ? null : ['id' => $round['id'], 'category' => $round['category']],
            $display['token'],
        );

        // The design's own uploads by the display preset (original until lily's imgproxy has it - DisplayImages)
        $images = [];
        foreach (DisplayDesignSanitizer::imagePaths($design) as $path) {
            $images[$path] = $this->displayImages->url($path);
        }

        return new CompetitionDisplayState(
            displayId: $display['id'],
            competitionId: $display['competition_id'],
            kind: DisplayKind::from($display['kind']),
            locale: $display['locale'],
            designVersion: $display['design_version'],
            competition: [
                'name' => $display['competition_name'],
                'logoUrl' => $this->publicPath($display['competition_logo']),
                'seriesLogoUrl' => $this->publicPath($display['series_logo']),
                'organizationLogoUrl' => $this->publicPath($display['organization_logo']),
                'location' => $display['location'],
                'countryCode' => CountryCode::fromCode($display['location_country_code'])?->name,
                'dateFrom' => $display['date_from'] !== null ? new DateTimeImmutable($display['date_from'])->format('Y-m-d') : null,
                'dateTo' => $display['date_to'] !== null ? new DateTimeImmutable($display['date_to'])->format('Y-m-d') : null,
            ],
            round: $round === null ? null : [
                'id' => $round['id'],
                'name' => $round['name'],
                'category' => $round['category'],
                'badge' => ['bg' => $round['badge_bg'], 'fg' => RoundBadgeColor::text($round['badge_bg'])],
                'minutesLimit' => $round['minutes_limit'],
                'startsAtMs' => RoundClock::milliseconds($round['starts_at']),
                'timezone' => RoundTimezone::resolve($round['timezone'], $display['location_country_code'], $display['series_country_code']),
                'resultsPublished' => $round['results_published_at'] !== null,
            ],
            stopwatch: $round === null ? null : new RoundStopwatchState(
                roundId: $round['id'],
                storedStatus: $round['stopwatch_status'],
                startedAt: $round['stopwatch_started_at'],
                stoppedAt: $round['stopwatch_stopped_at'],
                // Only an imported round has no limit (RoundStopwatchState::ofRound())
                minutesLimit: $round['minutes_limit'] ?? 0,
                asOf: $now,
            ),
            puzzles: $puzzles,
            anyPuzzleRevealed: $anyRevealed,
            refreshAtMs: $refreshAtMs,
            results: $results,
            images: $images,
            followedRoundIds: array_map(static fn (array $followedRound): string => $followedRound['id'], $followed),
            design: $design,
            event: new CompetitionReference(
                name: $display['competition_name'],
                slug: $display['competition_slug'],
                seriesName: $display['series_name'],
                seriesSlug: $display['series_slug'],
            ),
            roundSlug: $round['slug'] ?? null,
            standings: $standings,
        );
    }

    /**
     * The stored design of the display, for the renderer to reload when the state's `designVersion` grew.
     *
     * @return array{designVersion: int, design: array<string, mixed>}
     * @throws CompetitionDisplayNotFound for an unknown (or regenerated) token
     */
    public function designForToken(string $token): array
    {
        self::assertTokenShape($token);

        $row = $this->database->fetchAssociative(
            'SELECT design, design_version FROM competition_display WHERE token = :token',
            ['token' => $token],
        );

        if ($row === false) {
            throw new CompetitionDisplayNotFound();
        }

        /** @var array{design: string, design_version: int|string} $row */
        return [
            'designVersion' => (int) $row['design_version'],
            'design' => self::decodeDesign($row['design']),
        ];
    }

    /**
     * @return Display
     * @throws CompetitionDisplayNotFound
     */
    private function display(string $token): array
    {
        self::assertTokenShape($token);

        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    d.id,
    d.competition_id,
    d.token,
    d.kind,
    d.locale,
    d.design,
    d.design_version,
    d.follow_mode,
    d.pinned_round_id,
    d.follow_round_ids,
    d.uses_results,
    d.uses_live_results,
    c.name AS competition_name,
    c.slug AS competition_slug,
    c.logo AS competition_logo,
    c.location,
    c.location_country_code,
    c.date_from,
    c.date_to,
    cs.logo AS series_logo,
    cs.name AS series_name,
    cs.slug AS series_slug,
    cs.location_country_code AS series_country_code,
    o.logo AS organization_logo
FROM competition_display d
INNER JOIN competition c ON c.id = d.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
LEFT JOIN organization o ON o.id = COALESCE(c.organization_id, cs.organization_id)
WHERE d.token = :token
SQL,
            ['token' => $token],
        );

        if ($row === false) {
            throw new CompetitionDisplayNotFound();
        }

        /** @var array{id: string, competition_id: string, token: string, kind: string, locale: string, design: string, design_version: int|string, follow_mode: string, pinned_round_id: null|string, follow_round_ids: null|string, uses_results: bool, uses_live_results: bool, competition_name: string, competition_slug: null|string, competition_logo: null|string, location: null|string, location_country_code: null|string, date_from: null|string, date_to: null|string, series_logo: null|string, series_name: null|string, series_slug: null|string, series_country_code: null|string, organization_logo: null|string} $row */
        $followRoundIds = null;

        if ($row['follow_round_ids'] !== null) {
            $decoded = json_decode($row['follow_round_ids'], true);
            $followRoundIds = is_array($decoded) ? array_values(array_map(strtolower(...), array_filter($decoded, is_string(...)))) : null;
        }

        return [
            ...$row,
            'id' => strtolower($row['id']),
            'competition_id' => strtolower($row['competition_id']),
            'pinned_round_id' => $row['pinned_round_id'] !== null ? strtolower($row['pinned_round_id']) : null,
            'design' => self::decodeDesign($row['design']),
            'design_version' => (int) $row['design_version'],
            'follow_round_ids' => $followRoundIds,
        ];
    }

    /**
     * @return list<Round> by start (the schedule position decides the automatic badge colour, as on the event page)
     */
    private function rounds(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.slug,
    cr.category,
    cr.starts_at,
    cr.minutes_limit,
    cr.timezone,
    cr.badge_background_color,
    cr.stopwatch_status,
    cr.stopwatch_started_at,
    cr.stopwatch_stopped_at,
    cr.results_published_at
FROM competition_round cr
WHERE cr.competition_id = :competitionId
ORDER BY cr.starts_at, cr.id
SQL,
            ['competitionId' => $competitionId],
        );

        $rounds = [];

        foreach ($rows as $position => $row) {
            /** @var array{id: string, name: string, slug: null|string, category: string, starts_at: string, minutes_limit: null|int|string, timezone: null|string, badge_background_color: null|string, stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_stopped_at: null|string, results_published_at: null|string} $row */
            $rounds[] = [
                'id' => strtolower($row['id']),
                'name' => $row['name'],
                'slug' => $row['slug'],
                'category' => $row['category'],
                'starts_at' => new DateTimeImmutable($row['starts_at']),
                'minutes_limit' => $row['minutes_limit'] !== null ? (int) $row['minutes_limit'] : null,
                'timezone' => $row['timezone'],
                'badge_bg' => RoundBadgeColor::background($row['badge_background_color'], $position),
                'stopwatch_status' => $row['stopwatch_status'],
                'stopwatch_started_at' => self::date($row['stopwatch_started_at']),
                'stopwatch_stopped_at' => self::date($row['stopwatch_stopped_at']),
                'results_published_at' => self::date($row['results_published_at']),
            ];
        }

        return $rounds;
    }

    /**
     * @param Display $display
     * @param list<Round> $rounds
     * @return array{0: null|Round, 1: list<Round>} the round shown now, the rounds it is picked from
     */
    private static function followedRound(array $display, array $rounds, DateTimeImmutable $now): array
    {
        if ($display['follow_mode'] === DisplayFollowMode::Pinned->value && $display['pinned_round_id'] !== null) {
            foreach ($rounds as $round) {
                if ($round['id'] === $display['pinned_round_id']) {
                    return [$round, [$round]];
                }
            }
        }

        $candidates = $rounds;

        if ($display['follow_mode'] === DisplayFollowMode::Current->value && $display['follow_round_ids'] !== null) {
            $chosen = array_values(array_filter($rounds, static fn (array $round): bool => in_array($round['id'], $display['follow_round_ids'], true)));
            // Every chosen round deleted - the display keeps showing the event rather than nothing
            $candidates = $chosen !== [] ? $chosen : $rounds;
        }

        if ($candidates === []) {
            return [null, []];
        }

        $byId = [];
        $overviews = [];

        foreach ($candidates as $round) {
            $byId[$round['id']] = $round;
            $overviews[] = new RoundResultsOverview(
                roundId: $round['id'],
                name: $round['name'],
                category: '',
                startsAt: $round['starts_at'],
                minutesLimit: null,
                slug: null,
                stopwatchStatus: $round['stopwatch_status'],
                stopwatchStartedAt: $round['stopwatch_started_at'],
                stopwatchStoppedAt: $round['stopwatch_stopped_at'],
                resultsPublishedAt: $round['results_published_at'],
                resultsFirstPublishedAt: null,
                tableNumbersOff: false,
                puzzlesCount: 0,
                piecesCount: null,
                entriesTotal: 0,
                entriesWithTableNumber: 0,
                entriesWithResult: 0,
                entriesQualified: 0,
            );
        }

        $picked = LiveResultsCurrentRound::pick($overviews, $now);

        return [$picked !== null ? $byId[$picked->roundId] : null, $candidates];
    }

    /**
     * The next moment the current-round pick may change by itself (LiveResultsCurrentRound): a round's start, a
     * scheduled stopwatch start, the end of a started round's "recent" 12 hours. Stopwatch starts and stops come as
     * pushes; this covers what changes without a write.
     *
     * @param list<Round> $rounds
     */
    private static function nextPickChangeMs(array $rounds, DateTimeImmutable $now): null|int
    {
        $next = null;
        $recentFor = substr(LiveResultsCurrentRound::RECENTLY_STARTED, 1);

        foreach ($rounds as $round) {
            foreach ([$round['starts_at'], $round['stopwatch_started_at']] as $moment) {
                if ($moment === null) {
                    continue;
                }

                foreach ([$moment, $moment->modify('+' . $recentFor)] as $candidate) {
                    if ($candidate > $now && ($next === null || $candidate < $next)) {
                        $next = $candidate;
                    }
                }
            }
        }

        return $next !== null ? RoundClock::milliseconds($next) : null;
    }

    /**
     * @param Round $round
     * @param array<string, mixed> $design
     */
    private function results(array $round, array $design, bool $usesLiveResults, string $token): null|DisplayResults
    {
        $demand = DisplayDesignSanitizer::resultsDemand($design);

        if ($demand === []) {
            return null;
        }

        $published = $round['results_published_at'] !== null;
        // Before publishing, only what an element the organiser switched to live shows (context rule 5)
        $allowed = static fn (string $type): bool => isset($demand[$type]) && ($published || ($usesLiveResults && $demand[$type]['live']));

        if (!$allowed('finished_count') && !$allowed('leaderboard') && !$allowed('ticker')) {
            return new DisplayResults($published, null, null, [], []);
        }

        $entries = $round['category'] === 'solo' ? $this->people($round['id']) : $this->teams($round['id']);
        $ranks = OfficialResultsRanking::rank(array_map(static fn (array $entry): RoundEntryResult => $entry['result'], $entries));

        $rows = [];
        $total = 0;

        foreach ($entries as $index => $entry) {
            if ($entry['result']->didNotStart) {
                continue;
            }

            $total++;
            $rank = $ranks[$index];

            if ($rank === null) {
                continue;
            }

            $rows[] = new DisplayResultRow(
                // Opaque per display (keyed by its token): stable for DOM diffing, says nothing about the entry
                key: substr(hash_hmac('sha256', $entry['ref']->toString(), $token), 0, 16),
                rank: $rank,
                name: $entry['name'],
                members: $entry['members'],
                countries: $entry['countries'],
                resultSeconds: $entry['result']->seconds,
                piecesPlaced: $entry['result']->piecesPlaced,
                didNotFinish: $entry['result']->isUnfinished(),
                tableNumber: $entry['table_number'],
                enteredAtMs: $entry['entered_at'] !== null ? RoundClock::milliseconds($entry['entered_at']) : null,
            );
        }

        $top = [];
        if ($allowed('leaderboard')) {
            $top = $rows;
            usort($top, static fn (DisplayResultRow $a, DisplayResultRow $b): int => $a->rank <=> $b->rank ?: strcasecmp($a->name, $b->name) ?: strcmp($a->key, $b->key));
            $top = array_slice($top, 0, $demand['leaderboard']['rows']);
        }

        $latest = [];
        if ($allowed('ticker')) {
            $latest = $rows;
            usort($latest, static fn (DisplayResultRow $a, DisplayResultRow $b): int => ($b->enteredAtMs ?? 0) <=> ($a->enteredAtMs ?? 0) ?: $a->rank <=> $b->rank ?: strcmp($a->key, $b->key));
            $latest = array_slice($latest, 0, $demand['ticker']['rows']);
        }

        return new DisplayResults(
            published: $published,
            finished: $allowed('finished_count') ? count($rows) : null,
            total: $allowed('finished_count') ? $total : null,
            top: $top,
            latest: $latest,
        );
    }

    /**
     * @return list<array{ref: RoundEntryRef, name: string, members: list<string>, countries: list<string>, result: RoundEntryResult, table_number: null|int, entered_at: null|DateTimeImmutable}>
     */
    private function people(string $roundId): array
    {
        $going = CompetitionParticipantGoing::sql('cp');
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cpr.id,
    cpr.table_number,
    cpr.result_seconds,
    cpr.result_pieces_placed,
    cpr.result_did_not_start,
    cpr.result_entered_at,
    cp.name,
    COALESCE(cp.country, CASE WHEN p.is_private = false THEN p.country END) AS country
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND {$going}
LEFT JOIN player p ON p.id = cp.player_id
WHERE cpr.round_id = :roundId
SQL,
            ['roundId' => $roundId],
        );

        $entries = [];

        foreach ($rows as $row) {
            /** @var array{id: string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_entered_at: null|string, name: string, country: null|string} $row */
            $country = CountryCode::fromCode($row['country'])?->name;
            $entries[] = [
                'ref' => RoundEntryRef::participantRound($row['id']),
                'name' => $row['name'],
                'members' => [],
                'countries' => $country !== null ? [$country] : [],
                'result' => RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']),
                'table_number' => $row['table_number'],
                'entered_at' => self::date($row['result_entered_at']),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{ref: RoundEntryRef, name: string, members: list<string>, countries: list<string>, result: RoundEntryResult, table_number: null|int, entered_at: null|DateTimeImmutable}>
     */
    private function teams(string $roundId): array
    {
        $going = CompetitionParticipantGoing::sql('cp');
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    ct.id,
    ct.name,
    ct.table_number,
    ct.result_seconds,
    ct.result_pieces_placed,
    ct.result_did_not_start,
    ct.result_entered_at,
    (
        SELECT json_agg(json_build_object(
            'name', cp.name,
            'country', COALESCE(cp.country, CASE WHEN p.is_private = false THEN p.country END)
        ) ORDER BY cp.name, cp.id)
        FROM competition_participant_round cpr
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND {$going}
        LEFT JOIN player p ON p.id = cp.player_id
        WHERE cpr.team_id = ct.id
    ) AS members
FROM competition_team ct
WHERE ct.round_id = :roundId
SQL,
            ['roundId' => $roundId],
        );

        $entries = [];

        foreach ($rows as $row) {
            /** @var array{id: string, name: null|string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_entered_at: null|string, members: null|string} $row */
            /** @var list<array{name: string, country: null|string}> $members */
            $members = $row['members'] !== null ? json_decode($row['members'], true, flags: JSON_THROW_ON_ERROR) : [];
            $names = array_map(static fn (array $member): string => $member['name'], $members);
            $countries = [];

            foreach ($members as $member) {
                $country = CountryCode::fromCode($member['country'])?->name;

                if ($country !== null && !in_array($country, $countries, true)) {
                    $countries[] = $country;
                }
            }

            $entries[] = [
                'ref' => RoundEntryRef::team($row['id']),
                // An unnamed pair/team is its members, as on the round page
                'name' => $row['name'] ?? implode(', ', $names),
                'members' => $names,
                'countries' => $countries,
                'result' => RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']),
                'table_number' => $row['table_number'],
                'entered_at' => self::date($row['result_entered_at']),
            ];
        }

        return $entries;
    }

    /**
     * @throws CompetitionDisplayNotFound
     */
    private static function assertTokenShape(string $token): void
    {
        if (strlen($token) !== OnlineToken::LENGTH || preg_match('/^[1-9A-HJ-NP-Za-km-z]+$/', $token) !== 1) {
            throw new CompetitionDisplayNotFound();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeDesign(string $json): array
    {
        $design = json_decode($json, true);

        /** @var array<string, mixed> */
        return is_array($design) ? $design : [];
    }

    private function publicPath(null|string $path): null|string
    {
        return $path !== null && $path !== '' ? $this->uploaderHelper->getPublicPath($path) : null;
    }

    private function thumbnail(string $path, string $preset): string
    {
        return sprintf('%s/preset:%s/plain/%s', $this->nginxProxyBaseUrl, $preset, ltrim($path, '/'));
    }

    private static function date(null|string $value): null|DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }
}
