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
use SpeedPuzzling\Web\Results\RoundPuzzleSlot;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Results\RoundStopwatchState;
use SpeedPuzzling\Web\Services\ChallengeRanking;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayBracket;
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
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundTimezone;
use SpeedPuzzling\Web\Value\TableLabels;

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
 * - **Bracket** (v2 S6): for a shown `bracket` element the bracket of one category as a guest sees it (DisplayBracket,
 *   from the rounds read here - each box's status computed now; the steps and the entrants of published rounds cached
 *   per generation of the event's standings - 0 statements warm). No puzzle data.
 *
 * - **Live control** (v2 L1/L2): for a shown `message` element the event's live message (read with the display); for a
 *   shown `spotlight` element the shown round's spotlight (its ref read with the rounds) as a results row from the same
 *   entries statement - the name, table, members and flags always (the organiser chose to show them), its result and
 *   rank only as the element's source allows; did not start, removed or waitlisted = nobody.
 *
 * Three statements (display + event, rounds, visible puzzles), a fourth for the results entries only when the design
 * shows results that may be shown now.
 *
 * @phpstan-type Round array{id: string, name: string, slug: null|string, category: string, starts_at: DateTimeImmutable, minutes_limit: null|int, timezone: null|string, badge_bg: string, stopwatch_status: null|string, stopwatch_started_at: null|DateTimeImmutable, stopwatch_stopped_at: null|DateTimeImmutable, results_published_at: null|DateTimeImmutable, spotlight_ref: null|string, table_labels?: null|TableLabels}
 * @phpstan-type Display array{id: string, competition_id: string, token: string, kind: string, locale: string, design: array<string, mixed>, design_version: int, follow_mode: string, pinned_round_id: null|string, follow_round_ids: null|list<string>, uses_results: bool, uses_live_results: bool, competition_name: string, competition_slug: null|string, competition_logo: null|string, location: null|string, location_country_code: null|string, date_from: null|string, date_to: null|string, series_logo: null|string, series_name: null|string, series_slug: null|string, series_country_code: null|string, organization_logo: null|string, control_message: null|string}
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
        private DisplayBracket $displayBracket,
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
        $slots = [];
        $anyRevealed = false;
        $refreshAtMs = $display['follow_mode'] === DisplayFollowMode::Pinned->value && $round !== null && $round['id'] === $display['pinned_round_id']
            ? null
            : self::nextPickChangeMs($followed, $now);

        if ($roundId !== null) {
            $visible = $this->roundPuzzlesVisibleNow->forRound($roundId, $now);
            $anyRevealed = $visible->anyRevealed();
            // Only the views use them (a challenge's puzzle must be shown to everybody) - never sent
            $slots = $visible->slots;

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
        $spotlight = null;
        // The round's puzzle labels by position ("Challenge 3") - only for a design naming one (`puzzle_label`); labels
        // are public before the reveal too (the event page shows them in place of names)
        $puzzleLabels = null;

        if (DisplayDesignSanitizer::showsPuzzleLabels($design)) {
            $puzzleLabels = [];

            foreach ($slots as $slot) {
                $puzzleLabels[$slot->position] = $slot->label;
            }
        }

        if ($round !== null && $display['uses_results']) {
            [$results, $spotlight] = $this->results($round, $design, $display['uses_live_results'], $display['token'], $slots, $now);
        }

        // Published standings (v2 S2) - guest snapshots, cached per generation; a failure is an empty element, never a
        // failed state (DisplayStandings catches everything)
        $standings = $this->displayStandings->forDisplay(
            $design,
            $display['competition_id'],
            $round === null ? null : ['id' => $round['id'], 'category' => $round['category']],
            $display['token'],
        );

        // The bracket (v2 S6) - the rounds read above, the steps and published entrants cached per generation; a failure
        // is an empty element, never a failed state (DisplayBracket catches everything)
        $bracket = $this->displayBracket->forDisplay(
            $design,
            $display['competition_id'],
            $rounds,
            $round === null ? null : ['id' => $round['id'], 'category' => $round['category']],
            $now,
        );

        // The live control (v2 L1, displays-standings.md §5.2) - only for a design with a shown message element, read
        // with the display itself (statement 1): every other design's answer stays as it was
        $control = null;
        $picks = DisplayDesignSanitizer::spotlightPicks($design);

        if (DisplayDesignSanitizer::showsMessage($design) || $picks['chosen'] || $picks['latest']) {
            $control = ['message' => $display['control_message'], 'spotlight' => $spotlight];
        }

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
            control: $control,
            puzzleLabels: $puzzleLabels,
            bracket: $bracket,
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
    o.logo AS organization_logo,
    dc.message AS control_message
FROM competition_display d
INNER JOIN competition c ON c.id = d.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
LEFT JOIN organization o ON o.id = COALESCE(c.organization_id, cs.organization_id)
LEFT JOIN competition_display_control dc ON dc.competition_id = d.competition_id
WHERE d.token = :token
SQL,
            ['token' => $token],
        );

        if ($row === false) {
            throw new CompetitionDisplayNotFound();
        }

        /** @var array{id: string, competition_id: string, token: string, kind: string, locale: string, design: string, design_version: int|string, follow_mode: string, pinned_round_id: null|string, follow_round_ids: null|string, uses_results: bool, uses_live_results: bool, competition_name: string, competition_slug: null|string, competition_logo: null|string, location: null|string, location_country_code: null|string, date_from: null|string, date_to: null|string, series_logo: null|string, series_name: null|string, series_slug: null|string, series_country_code: null|string, organization_logo: null|string, control_message: null|string} $row */
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
    cr.results_published_at,
    cr.table_labels::text AS table_labels,
    sp.entry_ref AS spotlight_ref
FROM competition_round cr
LEFT JOIN competition_round_spotlight sp ON sp.round_id = cr.id
WHERE cr.competition_id = :competitionId
ORDER BY cr.starts_at, cr.id
SQL,
            ['competitionId' => $competitionId],
        );

        $rounds = [];

        foreach ($rows as $position => $row) {
            /** @var array{id: string, name: string, slug: null|string, category: string, starts_at: string, minutes_limit: null|int|string, timezone: null|string, badge_background_color: null|string, stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_stopped_at: null|string, results_published_at: null|string, table_labels: null|string, spotlight_ref: null|string} $row */
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
                'spotlight_ref' => $row['spotlight_ref'],
                // The names of its table numbers (table-labels-and-plates.md) - rows carry `tableLabel` only when set
                'table_labels' => TableLabels::fromStored($row['table_labels']),
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
     * @param list<RoundPuzzleSlot> $slots the round's puzzles as RoundPuzzlesVisibleNow gives them now
     * @return array{0: null|DisplayResults, 1: null|DisplayResultRow} the results part and the chosen spotlight's row
     *                                                              (v2 L2) - one entries statement for both
     */
    private function results(array $round, array $design, bool $usesLiveResults, string $token, array $slots, DateTimeImmutable $now): array
    {
        $demand = DisplayDesignSanitizer::resultsDemand($design);
        $views = DisplayDesignSanitizer::resultsViews($design);

        if ($demand === [] && $views === []) {
            return [null, null];
        }

        $published = $round['results_published_at'] !== null;
        // Before publishing, only what an element the organiser switched to live shows (context rule 5)
        $allowed = static fn (string $type): bool => isset($demand[$type]) && ($published || ($usesLiveResults && $demand[$type]['live']));
        $allowedViews = array_filter($views, static fn (array $view): bool => $published || ($usesLiveResults && $view['live']));
        // `views` only for a design that has an element showing a division or a challenge (real events L2b)
        $emptyViews = $views === [] ? null : [];
        // The live control's spotlight (v2 L2): the chosen entry is shown by the organiser's choice - its name even before
        // publishing; the latest finisher is a result, so only when results may show
        $picks = DisplayDesignSanitizer::spotlightPicks($design);
        $chosenRef = $picks['chosen'] && $round['spotlight_ref'] !== null ? strtolower($round['spotlight_ref']) : null;
        $latestPick = $picks['latest'] && $allowed('spotlight');
        $anyResults = $allowed('finished_count') || $allowed('leaderboard') || $allowed('ticker') || $allowedViews !== [] || $latestPick;

        if (!$anyResults && $chosenRef === null) {
            return [new DisplayResults($published, null, null, [], [], $emptyViews), null];
        }

        // What the views need of the round (one statement, only for a design with an allowed view)
        $facts = $allowedViews === [] ? null : $this->viewFacts($round['id']);
        $withDivisions = $facts !== null && $facts['division_ids'] !== [] && array_filter($allowedViews, static fn (array $view): bool => $view['division'] !== null) !== [];
        $withPuzzles = $facts !== null && $facts['block'] && array_filter($allowedViews, static fn (array $view): bool => $view['challenge'] !== null) !== [];

        $entries = $round['category'] === 'solo'
            ? $this->people($round['id'], $withDivisions, $withPuzzles)
            : $this->teams($round['id'], $withDivisions, $withPuzzles);
        $results = array_map(static fn (array $entry): RoundEntryResult => $entry['result'], $entries);
        $ranks = OfficialResultsRanking::rank($results);
        $labels = $round['table_labels'] ?? null;

        $rows = [];
        $total = 0;
        $finished = 0;
        $spotlight = null;
        // An entry that did not finish is ranked after every finisher - but while the round still runs (not published,
        // its time not over) the teams still solving may finish yet: a live board lists finishers only, and "finished"
        // counts finished entries only, never a "Didn't finish" (SPWS demo rehearsal, spws-demo-ux-leftovers.md §8) - the
        // ticker, the latest-finisher spotlight and the division views follow the board
        $roundOver = $published || self::roundIsOver($round, $now);
        $boardRows = [];

        foreach ($entries as $index => $entry) {
            if ($entry['result']->didNotStart) {
                continue;
            }

            $total++;
            $rank = $ranks[$index];

            if ($chosenRef !== null && $entry['ref']->toString() === $chosenRef) {
                $spotlight = self::spotlightRow($entry, $rank, $allowed('spotlight'), $token, $labels);
            }

            if ($rank === null) {
                continue;
            }

            $rows[$index] = self::row($entry, $rank, $token, $labels);

            if ($entry['result']->isFinished()) {
                $finished++;
            }

            if ($roundOver || $entry['result']->isUnfinished() === false) {
                $boardRows[$index] = $rows[$index];
            }
        }

        if (!$anyResults) {
            return [new DisplayResults($published, null, null, [], [], $emptyViews), $spotlight];
        }

        $top = [];
        if ($allowed('leaderboard')) {
            $top = self::best($boardRows, $demand['leaderboard']['rows']);
        }

        // The ticker's rows - and at least the newest one for a spotlight following the latest finisher (v2 L2)
        $latestRows = max($allowed('ticker') ? $demand['ticker']['rows'] : 0, $latestPick ? 1 : 0);
        $latest = [];
        if ($latestRows > 0) {
            // "Just finished" - a "Didn't finish" only once the round is over, like the board
            $latest = self::newest($boardRows, $latestRows);
        }

        $viewRows = $emptyViews;

        if ($facts !== null) {
            $viewRows = [];

            foreach ($allowedViews as $elementId => $view) {
                $chosen = $this->viewRows($view, $facts, $entries, $results, $boardRows, $token, $slots, $labels);
                $viewRows[$elementId] = $view['type'] === 'ticker' ? self::newest($chosen, $view['rows']) : self::best($chosen, $view['rows']);
            }
        }

        return [
            new DisplayResults(
                published: $published,
                finished: $allowed('finished_count') ? $finished : null,
                total: $allowed('finished_count') ? $total : null,
                top: $top,
                latest: $latest,
                views: $viewRows,
            ),
            $spotlight,
        ];
    }

    /**
     * The round's time is over: its stopwatch stopped, or running past its limit (RoundStopwatchStatus - the one rule);
     * a round never timed by the stopwatch by its schedule (its start + limit passed - an event over)
     *
     * @param Round $round
     */
    private static function roundIsOver(array $round, DateTimeImmutable $now): bool
    {
        $status = RoundStopwatchStatus::at($round['stopwatch_status'], $round['stopwatch_started_at'], $now);

        if ($status === RoundStopwatchStatus::STOPPED) {
            return true;
        }

        if ($status === null) {
            return $round['starts_at']->modify('+' . ($round['minutes_limit'] ?? 0) . ' minutes') <= $now;
        }

        if ($status !== RoundStopwatchStatus::RUNNING || $round['stopwatch_started_at'] === null) {
            return false;
        }

        // A round without a limit (imported) has no "time over" - its results show as before
        return $round['minutes_limit'] === null
            || $now->getTimestamp() - $round['stopwatch_started_at']->getTimestamp() >= $round['minutes_limit'] * 60;
    }

    /**
     * The rows of one view (real events L2b), keyed by entry index:
     * - a **challenge** of a solve-all block (`pick`, K ≥ 2) whose puzzle at that position is shown to everybody
     *   (RoundPuzzlesVisibleNow - a hidden puzzle never gets a ranking): its own time (RoundEntryPuzzles::duration(),
     *   the public round page's rule) ranked by ChallengeRanking over every entry that did that puzzle; the division is
     *   not applied (the public page's challenge view lists every division too);
     * - else a **division** the round uses: its entries with their division rank (OfficialResultsRanking::rankWithin(),
     *   over every entry - A13);
     * - else (a round without that division, not a block, a hidden or missing puzzle) the whole round, as the public
     *   page does for a value that names nothing.
     *
     * @param array{type: string, live: bool, rows: int, division: null|string, challenge: null|int} $view
     * @param array{block: bool, division_ids: list<string>} $facts
     * @param list<array{ref: RoundEntryRef, name: string, members: list<string>, countries: list<string>, result: RoundEntryResult, table_number: null|int, entered_at: null|DateTimeImmutable, division_id: null|string, puzzles: null|RoundEntryPuzzles}> $entries
     * @param list<RoundEntryResult> $results
     * @param array<int, DisplayResultRow> $rows the whole round's ranked rows
     * @param list<RoundPuzzleSlot> $slots
     * @return array<int, DisplayResultRow>
     */
    private function viewRows(array $view, array $facts, array $entries, array $results, array $rows, string $token, array $slots, null|TableLabels $labels): array
    {
        $slot = null;

        if ($view['challenge'] !== null && $facts['block']) {
            foreach ($slots as $candidate) {
                if ($candidate->position === $view['challenge'] && $candidate->isHidden() === false) {
                    $slot = $candidate;
                    break;
                }
            }
        }

        if ($view['type'] === 'leaderboard' && $slot !== null) {
            $roundPuzzleId = strtolower($slot->roundPuzzleId);
            $ownSeconds = [];

            foreach ($entries as $index => $entry) {
                if ($entry['result']->didNotStart) {
                    continue;
                }

                if ($entry['puzzles'] === null) {
                    // No per-puzzle record to tell: unranked, as on the public page
                    $ownSeconds[$index] = null;
                    continue;
                }

                foreach ($entry['puzzles']->items as $at => $item) {
                    if ($item->roundPuzzleId === $roundPuzzleId) {
                        $ownSeconds[$index] = $item->done ? $entry['puzzles']->duration($at) : null;
                        break;
                    }
                }

                // A record without this puzzle (not in $ownSeconds): the entry did another one (pick K of N)
            }

            $chosen = [];

            foreach (ChallengeRanking::rank($ownSeconds) as $index => $place) {
                if ($place !== null) {
                    $chosen[$index] = self::row($entries[$index], $place, $token, $labels, ownSeconds: $ownSeconds[$index]);
                }
            }

            return $chosen;
        }

        if ($view['division'] !== null && in_array($view['division'], $facts['division_ids'], true)) {
            $divisionRanks = OfficialResultsRanking::rankWithin($results, array_map(
                static fn (array $entry): null|string => $entry['division_id'] !== null && in_array($entry['division_id'], $facts['division_ids'], true) ? $entry['division_id'] : null,
                $entries,
            ));
            $chosen = [];

            foreach ($entries as $index => $entry) {
                $place = $divisionRanks[$index];

                if ($entry['division_id'] === $view['division'] && $place !== null && !$entry['result']->didNotStart) {
                    $chosen[$index] = self::row($entry, $place, $token, $labels);
                }
            }

            return $chosen;
        }

        return $rows;
    }

    /**
     * Whether the round is a solve-all block (`pick`, K ≥ 2) and the divisions it uses - the views' facts.
     *
     * @return array{block: bool, division_ids: list<string>}
     */
    private function viewFacts(string $roundId): array
    {
        /** @var false|array{puzzle_format: string, puzzles_to_solve: null|int, division_ids: null|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    cr.puzzle_format,
    cr.puzzles_to_solve,
    (SELECT json_agg(crd.division_id) FROM competition_round_division crd WHERE crd.round_id = cr.id) AS division_ids
FROM competition_round cr
WHERE cr.id = :roundId
SQL,
            ['roundId' => $roundId],
        );

        if ($row === false) {
            return ['block' => false, 'division_ids' => []];
        }

        $divisionIds = $row['division_ids'] !== null ? json_decode($row['division_ids'], true, flags: JSON_THROW_ON_ERROR) : [];

        return [
            'block' => $row['puzzle_format'] === RoundPuzzleFormat::Pick->value && $row['puzzles_to_solve'] !== null && $row['puzzles_to_solve'] >= 2,
            'division_ids' => is_array($divisionIds) ? array_values(array_map(strtolower(...), array_filter($divisionIds, is_string(...)))) : [],
        ];
    }

    /**
     * @param array{ref: RoundEntryRef, name: string, members: list<string>, countries: list<string>, result: RoundEntryResult, table_number: null|int, entered_at: null|DateTimeImmutable, division_id: null|string, puzzles: null|RoundEntryPuzzles} $entry
     * @param null|int $ownSeconds a challenge's own time - shown instead of the round's result
     */
    private static function row(array $entry, int $rank, string $token, null|TableLabels $labels, null|int $ownSeconds = null): DisplayResultRow
    {
        return new DisplayResultRow(
            // Opaque per display (keyed by its token): stable for DOM diffing, says nothing about the entry
            key: substr(hash_hmac('sha256', $entry['ref']->toString(), $token), 0, 16),
            rank: $rank,
            name: $entry['name'],
            members: $entry['members'],
            countries: $entry['countries'],
            resultSeconds: $ownSeconds ?? $entry['result']->seconds,
            piecesPlaced: $ownSeconds !== null ? null : $entry['result']->piecesPlaced,
            didNotFinish: $ownSeconds !== null ? false : $entry['result']->isUnfinished(),
            tableNumber: $entry['table_number'],
            enteredAtMs: $entry['entered_at'] !== null ? RoundClock::milliseconds($entry['entered_at']) : null,
            tableLabel: self::tableLabel($labels, $entry['table_number']),
        );
    }

    /**
     * The spotlit entry (v2 L2) - who and where always, the result and rank only when its source allows.
     *
     * @param array{ref: RoundEntryRef, name: string, members: list<string>, countries: list<string>, result: RoundEntryResult, table_number: null|int, entered_at: null|DateTimeImmutable, division_id: null|string, puzzles: null|RoundEntryPuzzles} $entry
     */
    private static function spotlightRow(array $entry, null|int $rank, bool $resultShown, string $token, null|TableLabels $labels): DisplayResultRow
    {
        return new DisplayResultRow(
            key: substr(hash_hmac('sha256', $entry['ref']->toString(), $token), 0, 16),
            rank: $resultShown ? $rank : null,
            name: $entry['name'],
            members: $entry['members'],
            countries: $entry['countries'],
            resultSeconds: $resultShown ? $entry['result']->seconds : null,
            piecesPlaced: $resultShown ? $entry['result']->piecesPlaced : null,
            didNotFinish: $resultShown && $entry['result']->isUnfinished(),
            tableNumber: $entry['table_number'],
            enteredAtMs: $resultShown && $entry['entered_at'] !== null ? RoundClock::milliseconds($entry['entered_at']) : null,
            tableLabel: self::tableLabel($labels, $entry['table_number']),
        );
    }

    /**
     * The label of an entry's table on a labelled round - null on a plain-numbers round and for a number the list does
     * not name (the display then shows the number, and the row's JSON has no `tableLabel`).
     */
    private static function tableLabel(null|TableLabels $labels, null|int $number): null|string
    {
        return $labels !== null && $number !== null && $labels->names($number) ? $labels->labelOf($number) : null;
    }

    /**
     * @param array<int, DisplayResultRow> $rows
     * @return list<DisplayResultRow> best first
     */
    private static function best(array $rows, int $limit): array
    {
        $rows = array_values($rows);
        usort($rows, static fn (DisplayResultRow $a, DisplayResultRow $b): int => $a->rank <=> $b->rank ?: strcasecmp($a->name, $b->name) ?: strcmp($a->key, $b->key));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param array<int, DisplayResultRow> $rows
     * @return list<DisplayResultRow> newest first
     */
    private static function newest(array $rows, int $limit): array
    {
        $rows = array_values($rows);
        usort($rows, static fn (DisplayResultRow $a, DisplayResultRow $b): int => ($b->enteredAtMs ?? 0) <=> ($a->enteredAtMs ?? 0) ?: $a->rank <=> $b->rank ?: strcmp($a->key, $b->key));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param bool $withDivisions read the entries' divisions (a division view - real events L2b)
     * @param bool $withPuzzles read the per-puzzle records (a challenge view)
     * @return list<array{ref: RoundEntryRef, name: string, members: list<string>, countries: list<string>, result: RoundEntryResult, table_number: null|int, entered_at: null|DateTimeImmutable, division_id: null|string, puzzles: null|RoundEntryPuzzles}>
     */
    private function people(string $roundId, bool $withDivisions = false, bool $withPuzzles = false): array
    {
        $going = CompetitionParticipantGoing::sql('cp');
        // Only a design with a division / challenge view reads more - every other display runs the statement as before
        $extra = ($withDivisions ? "\n    cpr.division_id," : '') . ($withPuzzles ? "\n    cpr.result_puzzles::text AS result_puzzles," : '');
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT{$extra}
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
            /** @var array{id: string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_entered_at: null|string, name: string, country: null|string, division_id?: null|string, result_puzzles?: null|string} $row */
            $country = CountryCode::fromCode($row['country'])?->name;
            $entries[] = [
                'ref' => RoundEntryRef::participantRound($row['id']),
                'name' => $row['name'],
                'members' => [],
                'countries' => $country !== null ? [$country] : [],
                'result' => RoundEntryResult::fromColumns($row['result_seconds'], $row['result_pieces_placed'], $row['result_did_not_start']),
                'table_number' => $row['table_number'],
                'entered_at' => self::date($row['result_entered_at']),
                'division_id' => isset($row['division_id']) ? strtolower($row['division_id']) : null,
                'puzzles' => isset($row['result_puzzles']) ? RoundEntryPuzzles::fromStored($row['result_puzzles']) : null,
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{ref: RoundEntryRef, name: string, members: list<string>, countries: list<string>, result: RoundEntryResult, table_number: null|int, entered_at: null|DateTimeImmutable, division_id: null|string, puzzles: null|RoundEntryPuzzles}>
     */
    private function teams(string $roundId, bool $withDivisions = false, bool $withPuzzles = false): array
    {
        $going = CompetitionParticipantGoing::sql('cp');
        $extra = ($withDivisions ? "\n    ct.division_id," : '') . ($withPuzzles ? "\n    ct.result_puzzles::text AS result_puzzles," : '');
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT{$extra}
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
            /** @var array{id: string, name: null|string, table_number: null|int, result_seconds: null|int, result_pieces_placed: null|int, result_did_not_start: bool, result_entered_at: null|string, members: null|string, division_id?: null|string, result_puzzles?: null|string} $row */
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
                'division_id' => isset($row['division_id']) ? strtolower($row['division_id']) : null,
                'puzzles' => isset($row['result_puzzles']) ? RoundEntryPuzzles::fromStored($row['result_puzzles']) : null,
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
