<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetCompetitionDisplayState;
use SpeedPuzzling\Web\Query\OfficialEntrantsView;
use SpeedPuzzling\Web\Results\Bracket\Bracket;
use SpeedPuzzling\Web\Results\Bracket\BracketConnector;
use SpeedPuzzling\Web\Results\Bracket\BracketRound;
use SpeedPuzzling\Web\Results\PublishedRoundEntrant;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use SpeedPuzzling\Web\Value\BracketRoundStatus;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The bracket part of a display's state (competition toolkit v2 S6, displays-standings.md §3.3): for every shown
 * `bracket` element the bracket of one category as a guest sees it on the public bracket page - stages as columns, a
 * box per round, the lines between neighbouring stages (BracketLayout::of()), and in a box only once the round's results
 * are published its qualified entrants (or its top 3 when it qualified nobody - BracketRound::withEntrants()), read
 * through OfficialEntrantsView as a guest: published rounds only, nobody hidden (no viewer - the displays' documented
 * blocklist exemption), private players masked (the organiser's name, nothing of their profile). No puzzle data at all.
 *
 * - `auto` = the bracket of the shown round's category, else the first bracket (by its earliest round); a category =
 *   that category's bracket. No bracket (no steps, every step broken, clashing steps) → null (the element's `whenEmpty`).
 * - The rounds are the ones the display read anyway (GetCompetitionDisplayState's rounds statement - the same rounds in
 *   the same order as GetBracketRounds), so a box's status (upcoming / live / results coming / results) is computed
 *   now, on every state, from the stopwatch it carries - never cached.
 * - **Cached as a guest snapshot** in the standings pool under the event's standings generation
 *   (PublicStandingSnapshots::generation() - every advancement-step write starts a new one) plus a fingerprint of the
 *   rounds (name, category, start, published or not - publishing a round's results changes it at once). Results
 *   edited inside a round already published heal within BRACKET_LIFETIME (they bump the generation only when a
 *   published standing counts the round).
 * - Names cut to the element's `names` (0-8) per box, `more` = how many more the box would list.
 * - **A bracket failure never blanks the timer**: everything sits in one try/catch → every element null + a warning.
 *
 * Statements: warm 0 (the generation and the snapshot cached); cold: the steps (1), the entrants of the published
 * bracket rounds (≤ 2: people, teams). No instance state (readonly).
 *
 * @phpstan-import-type Round from GetCompetitionDisplayState
 * @phpstan-type BracketEntrant array{rank: int, name: string, qualified: bool}
 * @phpstan-type CachedRound array{id: string, entrants: list<BracketEntrant>, visibleCount: int, showsTop: bool}
 * @phpstan-type CachedBracket array{category: string, stages: list<list<CachedRound>>, gaps: list<list<array{fromY: int, toY: int}>>}
 * @phpstan-type DisplayBracketRound array{name: string, status: string, current: bool, showsTop: bool, entrants: list<BracketEntrant>, more: int}
 * @phpstan-type DisplayBracketState array{category: string, stages: list<array{rounds: list<DisplayBracketRound>}>, gaps: list<list<array{fromY: int, toY: int}>>}
 */
final readonly class DisplayBracket
{
    // A results edit inside a published round of an event without a published standing is not signalled to the cache
    public const int BRACKET_LIFETIME = 15;
    private const string VERSION = 'v1';

    public function __construct(
        private GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
        private OfficialEntrantsView $officialEntrantsView,
        private PublicStandingSnapshots $snapshots,
        private CacheInterface $competitionStandingsCache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $design the display's normalised design
     * @param list<Round> $rounds every round of the event, in schedule order (the display's rounds statement)
     * @param null|array{id: string, category: string} $round the round the display shows now
     * @return null|array<string, null|DisplayBracketState> element id => its bracket; null = no shown bracket element
     */
    public function forDisplay(array $design, string $competitionId, array $rounds, null|array $round, DateTimeImmutable $now): null|array
    {
        $demand = DisplayDesignSanitizer::bracketDemand($design);

        if ($demand === []) {
            return null;
        }

        try {
            $brackets = $this->brackets(strtolower($competitionId), $rounds);
            $byId = [];

            foreach ($rounds as $item) {
                $byId[strtolower($item['id'])] = $item;
            }

            $result = [];

            foreach ($demand as $elementId => $element) {
                $bracket = self::pick($brackets, $element['category'], $round);
                $result[$elementId] = $bracket !== null ? self::shape($bracket, $byId, $element['names'], $round, $now) : null;
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not read the bracket of a display - shown empty', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);

            return array_map(static fn (): null => null, $demand);
        }
    }

    /**
     * @param list<CachedBracket> $brackets
     * @param null|array{id: string, category: string} $round
     * @return null|CachedBracket
     */
    private static function pick(array $brackets, string $category, null|array $round): null|array
    {
        if ($category !== 'auto') {
            foreach ($brackets as $bracket) {
                if ($bracket['category'] === $category) {
                    return $bracket;
                }
            }

            return null;
        }

        if ($round !== null) {
            foreach ($brackets as $bracket) {
                if ($bracket['category'] === $round['category']) {
                    return $bracket;
                }
            }
        }

        return $brackets[0] ?? null;
    }

    /**
     * @param CachedBracket $bracket
     * @param array<string, Round> $rounds
     * @param null|array{id: string, category: string} $shown
     * @return DisplayBracketState
     */
    private static function shape(array $bracket, array $rounds, int $names, null|array $shown, DateTimeImmutable $now): array
    {
        $shownId = $shown !== null ? strtolower($shown['id']) : null;
        $stages = [];

        foreach ($bracket['stages'] as $stage) {
            $boxes = [];

            foreach ($stage as $cached) {
                $round = $rounds[$cached['id']] ?? null;

                if ($round === null) {
                    // The rounds are part of the snapshot's key - only a round deleted between the two reads gets here
                    continue;
                }

                $status = self::status($round, $now);
                $published = $status === BracketRoundStatus::Published;
                $entrants = $published ? array_slice($cached['entrants'], 0, $names) : [];

                $boxes[] = [
                    'name' => $round['name'],
                    'status' => $status->value,
                    'current' => $cached['id'] === $shownId,
                    'showsTop' => $published && $cached['showsTop'],
                    'entrants' => $entrants,
                    'more' => $published ? max(0, $cached['visibleCount'] - count($entrants)) : 0,
                ];
            }

            $stages[] = ['rounds' => $boxes];
        }

        return [
            'category' => $bracket['category'],
            'stages' => $stages,
            'gaps' => $bracket['gaps'],
        ];
    }

    /**
     * @param Round $round
     */
    private static function status(array $round, DateTimeImmutable $now): BracketRoundStatus
    {
        return BracketRoundStatus::of(
            $round['results_published_at'] !== null,
            $round['stopwatch_status'],
            $round['stopwatch_started_at'],
            $round['starts_at'],
            $now,
        );
    }

    /**
     * The event's brackets as a guest sees them - cached per generation and rounds fingerprint.
     *
     * @param list<Round> $rounds
     * @return list<CachedBracket>
     */
    private function brackets(string $competitionId, array $rounds): array
    {
        $fingerprint = substr(sha1(json_encode(array_map(static fn (array $round): array => [
            strtolower($round['id']),
            $round['name'],
            $round['category'],
            $round['starts_at']->getTimestamp(),
            $round['results_published_at'] !== null,
        ], $rounds), JSON_THROW_ON_ERROR)), 0, 16);
        $key = sprintf('bracket_%s_%s_%s_%s', self::VERSION, $competitionId, $this->snapshots->generation($competitionId), $fingerprint);
        $compute = fn (): array => $this->compute($competitionId, $rounds);
        $computing = false;

        try {
            $brackets = $this->competitionStandingsCache->get($key, static function (ItemInterface $item) use ($compute, &$computing): array {
                $computing = true;
                $item->expiresAfter(self::BRACKET_LIFETIME);

                return $compute();
            });
        } catch (\Throwable $exception) {
            // The computation's own failure is not the cache's - it would fail the same way again
            if ($computing) {
                throw $exception;
            }

            $this->logger->warning('The standings cache failed - the bracket of a display computed without it', [
                'key' => $key,
                'exception' => $exception,
            ]);

            return $compute();
        }

        if (!is_array($brackets)) {
            return $compute();
        }

        /** @var list<CachedBracket> $brackets */
        return $brackets;
    }

    /**
     * @param list<Round> $rounds
     * @return list<CachedBracket>
     */
    private function compute(string $competitionId, array $rounds): array
    {
        $steps = $this->getCompetitionAdvancementSteps->ofCompetition($competitionId);

        if ($steps === []) {
            return [];
        }

        $bracketRounds = array_map(static fn (array $round): BracketRound => new BracketRound(
            id: strtolower($round['id']),
            name: $round['name'],
            category: RoundCategory::from($round['category']),
            startsAt: $round['starts_at'],
            startTimeKnown: true,
            // Neither the zone nor the status of the layout is used: the status is computed on every state (shape())
            timezone: 'UTC',
            status: $round['results_published_at'] !== null ? BracketRoundStatus::Published : BracketRoundStatus::Upcoming,
            slug: $round['slug'],
        ), $rounds);

        $brackets = BracketLayout::of($steps, $bracketRounds);

        $published = [];
        foreach ($brackets as $bracket) {
            foreach ($bracket->rounds() as $round) {
                if ($round->isPublished()) {
                    $published[$round->id] = $round->category;
                }
            }
        }

        // As a guest: no viewer, nobody hidden by a signed-in user's blocklist, nobody unmasked by an allow list
        $entrants = $published !== [] ? $this->officialEntrantsView->forRounds($published, null, asGuest: true) : [];

        return array_map(static fn (Bracket $bracket): array => [
            'category' => $bracket->category->value,
            'stages' => array_map(static fn (array $stage): array => array_map(static function (BracketRound $round) use ($entrants): array {
                $round = isset($entrants[$round->id]) ? $round->withEntrants($entrants[$round->id]) : $round;

                return [
                    'id' => $round->id,
                    'entrants' => array_map(self::entrant(...), array_slice($round->shownEntries, 0, DisplayDesignSanitizer::BRACKET_MAX_NAMES)),
                    'visibleCount' => $round->visibleCount,
                    'showsTop' => $round->showsTop,
                ];
            }, $stage), $bracket->stages),
            'gaps' => array_map(static fn (array $gap): array => array_map(static fn (BracketConnector $line): array => [
                'fromY' => $line->fromY,
                'toY' => $line->toY,
            ], $gap), $bracket->gaps),
        ], $brackets);
    }

    /**
     * The organiser's names only (a private player's too - never their profile name, id or #code): a team's name, else
     * its people's names.
     *
     * @return BracketEntrant
     */
    private static function entrant(PublishedRoundEntry $entry): array
    {
        $name = $entry->isTeam && $entry->teamName !== null && $entry->teamName !== ''
            ? $entry->teamName
            : implode(' & ', array_map(static fn (PublishedRoundEntrant $entrant): string => $entrant->playerName, $entry->entrants));

        return [
            'rank' => $entry->rank,
            'name' => $name,
            'qualified' => $entry->qualified,
        ];
    }
}
