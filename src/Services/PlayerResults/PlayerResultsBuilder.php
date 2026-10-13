<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PlayerResults;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\GetPlayerResultsSearchMatches;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Results\PlayerResultsPage;
use SpeedPuzzling\Web\Results\PuzzleDifficultyRating;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Services\ResolveDifficultyTiers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DifficultyFilter;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PlayerResultsCriteria;
use SpeedPuzzling\Web\Value\PlayerResultsSort;
use SpeedPuzzling\Web\Value\PlayerResultsView;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A player's results, filtered, grouped and sorted (docs/features/navigation README §4.7, PROF-05a) - the logic the
 * profile's PlayerSolvedPuzzles component had, shared by the Results page and that component.
 *
 * The whole history is loaded (three statements - GetPlayerSolvedPuzzles' solo, pair and team lists, + the members'
 * difficulty) and filtered, grouped, sorted and paged in PHP: filters, the attempt that leads a row, PPM, difficulty and
 * speed live here, so the statement count does not depend on the history's size. Ranks only for the shown solo rows
 * (one statement; with the Best rank sort every rank, still one), the search one statement more when something was
 * typed.
 *
 * Two semantics:
 * - Results (default): a By-puzzle row leads with the player's BEST attempt (the fastest; under "first tries" /
 *   "unboxed" the best such attempt) and every sort orders by that attempt; default sort Newest (D11, decision 69).
 *   Every comparator ends on stable ids, so a reload never reorders equal rows (Back restore, PROF-05c).
 * - legacy: the profile component's own (the leading attempt depends on the sort, default Fastest, the search folded in
 *   PHP) - kept for its one remaining release (implementation plan decision 97); docs/TODO.md removes it.
 */
final class PlayerResultsBuilder implements ResetInterface
{
    /**
     * Histories loaded in this request, by player id (a component re-renders the same player)
     *
     * @var array<string, array{solo: list<SolvedPuzzle>, duo: list<SolvedPuzzle>, team: list<SolvedPuzzle>}>
     */
    private array $histories = [];

    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetPlayerResultsSearchMatches $getPlayerResultsSearchMatches,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ResolveDifficultyTiers $resolveDifficultyTiers,
        readonly private PuzzlesSorter $puzzlesSorter,
    ) {
    }

    public function reset(): void
    {
        $this->histories = [];
    }

    /**
     * @param null|int $shownRows how many rows of the shown tab are rendered - null = the criteria's page (Results);
     *                            the legacy component passes its own limit
     */
    public function build(string $playerId, PlayerResultsCriteria $criteria, bool $legacy = false, null|int $shownRows = null): PlayerResultsPage
    {
        $history = $this->history($playerId);
        $all = [...$history['solo'], ...$history['duo'], ...$history['team']];
        $puzzleIds = array_values(array_unique(array_map(static fn (SolvedPuzzle $puzzle): string => $puzzle->puzzleId, $all)));

        // Difficulty is members-only: for everyone else it is not even queried. The whole history - the filter needs
        // every tier
        $ratings = $this->resolveDifficultyTiers->ratingsForViewer($this->retrieveLoggedUserProfile->getProfile(), $puzzleIds);
        $withDifficulty = $ratings !== null;
        $tiers = array_map(static fn (PuzzleDifficultyRating $rating): DifficultyTier => $rating->tier, $ratings ?? []);
        $scores = array_map(static fn (PuzzleDifficultyRating $rating): float => $rating->score, $ratings ?? []);

        $sort = $criteria->sort;

        // Criteria strip a non-member's difficulty sort already - a lapsed membership mid-request falls back the same way
        if ($withDifficulty === false && $sort->isMembersOnly()) {
            $sort = $legacy ? PlayerResultsSort::Fastest : PlayerResultsSort::DEFAULT;
        }

        $searchMatches = null;

        if ($criteria->query !== null && $legacy === false) {
            $searchMatches = $this->getPlayerResultsSearchMatches->puzzleIds($criteria->query, $puzzleIds);
        }

        $solo = $this->filtered($history['solo'], $criteria, false, $searchMatches, $tiers, $legacy);
        $duo = $this->filtered($history['duo'], $criteria, true, $searchMatches, $tiers, $legacy);
        $team = $this->filtered($history['team'], $criteria, true, $searchMatches, $tiers, $legacy);

        // Links leave the tab out only when it is the history's default: a pair/team filter's tab (the default while the
        // filter is on) stays named, so "Clear all" and removing the filter keep it
        $defaultCategory = $legacy ? PlayerResultsCriteria::CATEGORY_SOLO : self::defaultCategory($history, null);
        $category = $criteria->category ?? ($legacy ? $defaultCategory : self::defaultCategory($history, $criteria->team));
        $view = $legacy ? PlayerResultsView::Puzzles : $criteria->view;

        if ($legacy) {
            [$soloRows, $duoRows, $teamRows] = $this->legacyRows($solo, $duo, $team, $sort, $criteria, $scores);
            $shownSort = $sort;
        } else {
            $rankSort = $sort === PlayerResultsSort::Rank && $sort->appliesTo($category, $view);
            // Best rank needs every rank (one statement, memoised - the shown rows' ranks below read it)
            $ranks = $rankSort ? $this->getRanking->allForPlayer($playerId) : [];

            $soloRows = $this->tabRows($solo, PlayerResultsCriteria::CATEGORY_SOLO, $view, $sort, $criteria, $scores, $ranks);
            $duoRows = $this->tabRows($duo, PlayerResultsCriteria::CATEGORY_DUO, $view, $sort, $criteria, $scores, $ranks);
            $teamRows = $this->tabRows($team, PlayerResultsCriteria::CATEGORY_GROUP, $view, $sort, $criteria, $scores, $ranks);
            $shownSort = $sort->appliesTo($category, $view) ? $sort : PlayerResultsSort::DEFAULT;
        }

        $tabRows = match ($category) {
            PlayerResultsCriteria::CATEGORY_DUO => $duoRows,
            PlayerResultsCriteria::CATEGORY_GROUP => $teamRows,
            default => $soloRows,
        };

        $page = 1;

        if ($shownRows === null) {
            $page = min($criteria->page, max(1, (int) ceil(count($tabRows) / PlayerResultsPage::PAGE_SIZE)));
            $shownRows = $page * PlayerResultsPage::PAGE_SIZE;
        }

        // Ranks only for the rows on screen - a solo By-puzzle row shows its rank, other rows none
        $ranking = [];

        if ($category === PlayerResultsCriteria::CATEGORY_SOLO && $view === PlayerResultsView::Puzzles) {
            $shownPuzzleIds = [];

            foreach (array_slice($tabRows, 0, $shownRows) as $row) {
                $shownPuzzleIds[] = $row[0]->puzzleId;
            }

            $ranking = $this->getRanking->forPuzzles($playerId, $shownPuzzleIds);
        }

        return new PlayerResultsPage(
            playerId: $playerId,
            criteria: $criteria,
            category: $category,
            defaultCategory: $defaultCategory,
            view: $view,
            sort: $shownSort,
            soloRows: $soloRows,
            duoRows: $duoRows,
            teamRows: $teamRows,
            unfilteredRowsCount: self::unfilteredRowsCount($history, $category, $view),
            shownRowsCount: $shownRows,
            page: $page,
            ranking: $ranking,
            withDifficulty: $withDifficulty,
            difficultyTiers: $tiers,
            availableBrands: self::availableBrands($all),
            availableTeams: self::availableTeams($playerId, [...$history['duo'], ...$history['team']]),
            availablePiecePresets: self::availablePiecePresets($all, $criteria->pieces),
            difficultyOptions: self::difficultyOptions($all, $withDifficulty, $criteria->difficultyTiers, $tiers),
            resultsCount: count($all),
        );
    }

    /**
     * @return array{solo: list<SolvedPuzzle>, duo: list<SolvedPuzzle>, team: list<SolvedPuzzle>}
     */
    private function history(string $playerId): array
    {
        return $this->histories[$playerId] ??= [
            'solo' => array_values($this->getPlayerSolvedPuzzles->soloByPlayerId($playerId)),
            'duo' => array_values($this->getPlayerSolvedPuzzles->duoByPlayerId($playerId)),
            'team' => array_values($this->getPlayerSolvedPuzzles->teamByPlayerId($playerId)),
        ];
    }

    /**
     * Solo, or - for a player without solo results - the first tab with results. A pair/team filter without a tab opens
     * that pair's or team's tab.
     *
     * @param array{solo: list<SolvedPuzzle>, duo: list<SolvedPuzzle>, team: list<SolvedPuzzle>} $history
     */
    private static function defaultCategory(array $history, null|string $team): string
    {
        if ($team !== null) {
            foreach ([PlayerResultsCriteria::CATEGORY_DUO => $history['duo'], PlayerResultsCriteria::CATEGORY_GROUP => $history['team']] as $category => $puzzles) {
                foreach ($puzzles as $puzzle) {
                    if ($puzzle->teamId === $team) {
                        return $category;
                    }
                }
            }
        }

        return match (true) {
            $history['solo'] !== [] => PlayerResultsCriteria::CATEGORY_SOLO,
            $history['duo'] !== [] => PlayerResultsCriteria::CATEGORY_DUO,
            $history['team'] !== [] => PlayerResultsCriteria::CATEGORY_GROUP,
            default => PlayerResultsCriteria::CATEGORY_SOLO,
        };
    }

    /**
     * The shown tab's rows without any filter, in the shown view - the "of M" of "N of M"
     *
     * @param array{solo: list<SolvedPuzzle>, duo: list<SolvedPuzzle>, team: list<SolvedPuzzle>} $history
     */
    private static function unfilteredRowsCount(array $history, string $category, PlayerResultsView $view): int
    {
        $puzzles = match ($category) {
            PlayerResultsCriteria::CATEGORY_DUO => $history['duo'],
            PlayerResultsCriteria::CATEGORY_GROUP => $history['team'],
            default => $history['solo'],
        };

        if ($view === PlayerResultsView::Every) {
            return count($puzzles);
        }

        $keys = [];

        foreach ($puzzles as $puzzle) {
            $keys[$category === PlayerResultsCriteria::CATEGORY_SOLO ? $puzzle->puzzleId : self::teamRowKey($puzzle)] = true;
        }

        return count($keys);
    }

    /**
     * @param list<SolvedPuzzle> $puzzles
     * @param null|array<string, true> $searchMatches the puzzles the search found (Results); null = no search or legacy
     * @param array<string, DifficultyTier> $tiers
     * @return list<SolvedPuzzle>
     */
    private function filtered(
        array $puzzles,
        PlayerResultsCriteria $criteria,
        bool $withTeamFilter,
        null|array $searchMatches,
        array $tiers,
        bool $legacy,
    ): array {
        $legacySearch = $legacy ? SearchText::fold($criteria->query ?? '') : '';
        // A puzzle comes back once per result - its names are folded once
        $searchableTexts = [];
        $from = $criteria->dateFrom?->setTime(0, 0, 0);
        $to = $criteria->dateTo?->setTime(23, 59, 59);

        return array_values(array_filter($puzzles, static function (SolvedPuzzle $puzzle) use ($criteria, $withTeamFilter, $searchMatches, $tiers, $legacySearch, &$searchableTexts, $from, $to): bool {
            // Free filters - for everyone

            if ($criteria->brand !== null && $puzzle->manufacturerName !== $criteria->brand) {
                return false;
            }

            // The pair/team filter narrows only the pair and team lists, solo results belong to none
            if ($withTeamFilter && $criteria->team !== null && $puzzle->teamId !== $criteria->team) {
                return false;
            }

            if ($criteria->pieces !== null && $criteria->pieces->contains($puzzle->piecesCount) === false) {
                return false;
            }

            if ($searchMatches !== null && isset($searchMatches[$puzzle->puzzleId]) === false) {
                return false;
            }

            // The profile component's search: every name of the puzzle and its brand code, folded like the typed text
            if ($legacySearch !== '' && str_contains($searchableTexts[$puzzle->puzzleId] ??= self::searchableText($puzzle), $legacySearch) === false) {
                return false;
            }

            // Members-only filters - PlayerResultsCriteria drops them for everyone else

            // Relax = no time
            if ($criteria->onlyRelax && $puzzle->time !== null) {
                return false;
            }

            $finishedAt = $puzzle->finishedAt ?? $puzzle->trackedAt;

            if (($from !== null && $finishedAt < $from) || ($to !== null && $finishedAt > $to)) {
                return false;
            }

            if (self::matchesSpeed($criteria, $puzzle->time, $puzzle->piecesCount) === false) {
                return false;
            }

            if ($criteria->difficultyTiers !== [] && in_array((string) self::difficultyOf($puzzle, $tiers), $criteria->difficultyTiers, true) === false) {
                return false;
            }

            return true;
        }));
    }

    /**
     * One line per name (main title first) and the brand code as printed, each folded (SearchText) - a typed text
     * never matches across two of them (legacy search)
     */
    private static function searchableText(SolvedPuzzle $puzzle): string
    {
        return PuzzleSearchKeys::names($puzzle->puzzleName, $puzzle->puzzleAlternativeNames)
            . SearchText::fold($puzzle->puzzleIdentificationNumber ?? '') . "\n";
    }

    /**
     * The tier value, PuzzleSearchCriteria::UNRATED_DIFFICULTY for a puzzle without one yet
     *
     * @param array<string, DifficultyTier> $tiers
     */
    private static function difficultyOf(SolvedPuzzle $puzzle, array $tiers): int
    {
        return $tiers[$puzzle->puzzleId]->value ?? PuzzleSearchCriteria::UNRATED_DIFFICULTY;
    }

    private static function matchesSpeed(PlayerResultsCriteria $criteria, null|int $time, int $piecesCount): bool
    {
        if ($criteria->speedValue === null) {
            return true;
        }

        // A result without a time has no speed
        if ($time === null || $time === 0) {
            return false;
        }

        $faster = $criteria->speedComparison === PlayerResultsCriteria::SPEED_FASTER;

        if ($criteria->speedUnit === PlayerResultsCriteria::SPEED_UNIT_MINUTES) {
            $minutes = $time / 60;

            return $faster ? $minutes < $criteria->speedValue : $minutes > $criteria->speedValue;
        }

        // Pieces per minute
        $ppm = ($piecesCount * 60) / $time;

        return $faster ? $ppm > $criteria->speedValue : $ppm < $criteria->speedValue;
    }

    /**
     * The profile component's rows, exactly as it built them: the leading attempt of a row depends on the sort
     *
     * @param list<SolvedPuzzle> $solo
     * @param list<SolvedPuzzle> $duo
     * @param list<SolvedPuzzle> $team
     * @param array<string, float> $scores
     * @return array{array<array-key, non-empty-list<SolvedPuzzle>>, array<array-key, non-empty-list<SolvedPuzzle>>, array<array-key, non-empty-list<SolvedPuzzle>>}
     */
    private function legacyRows(array $solo, array $duo, array $team, PlayerResultsSort $sort, PlayerResultsCriteria $criteria, array $scores): array
    {
        $first = $criteria->onlyFirstTries;
        $unboxed = $criteria->onlyUnboxed;
        $sorter = $this->puzzlesSorter;

        // Solo puzzles grouped; first tries / unboxed keep the puzzles with such an attempt, and it leads the row
        $soloGrouped = $sorter->filterGroupedByAttempt($sorter->groupPuzzles($solo, withReordering: false), $first, $unboxed);
        $soloGrouped = match ($sort) {
            PlayerResultsSort::Slowest => $sorter->sortGoupedBySlowest($soloGrouped, $first, $unboxed),
            PlayerResultsSort::Newest => $sorter->sortGroupedByNewest($soloGrouped, $first, $unboxed),
            PlayerResultsSort::Oldest => $sorter->sortGroupedByOldest($soloGrouped, $first, $unboxed),
            PlayerResultsSort::FastestPpm => $sorter->sortGroupedByFastestPpm($soloGrouped, $first, $unboxed),
            PlayerResultsSort::SlowestPpm => $sorter->sortGroupedBySlowestPpm($soloGrouped, $first, $unboxed),
            PlayerResultsSort::Easiest => $sorter->sortGroupedByDifficulty($soloGrouped, $scores, false, $first, $unboxed),
            PlayerResultsSort::Hardest => $sorter->sortGroupedByDifficulty($soloGrouped, $scores, true, $first, $unboxed),
            default => $sorter->sortGroupedByFastest($soloGrouped, $first, $unboxed),
        };

        // One row per puzzle and pair/team: the row opens the result detail, which shows exactly those people. A pair's
        // first try is everybody's (docs/features/first-try-integrity.md), so its own flag is all the filter reads
        return [
            self::lists($soloGrouped),
            self::lists($sorter->groupPuzzlesByTeam($this->legacySorted($duo, $sort, $scores), $first, $unboxed)),
            self::lists($sorter->groupPuzzlesByTeam($this->legacySorted($team, $sort, $scores), $first, $unboxed)),
        ];
    }

    /**
     * The profile component's pair/team order: the results sorted, then grouped in that order
     *
     * @param list<SolvedPuzzle> $puzzles
     * @param array<string, float> $scores
     * @return array<SolvedPuzzle>
     */
    private function legacySorted(array $puzzles, PlayerResultsSort $sort, array $scores): array
    {
        return match ($sort) {
            PlayerResultsSort::Slowest => $this->puzzlesSorter->sortBySlowest($puzzles),
            PlayerResultsSort::Newest => $this->puzzlesSorter->sortByNewest($puzzles),
            PlayerResultsSort::Oldest => $this->puzzlesSorter->sortByOldest($puzzles),
            PlayerResultsSort::FastestPpm => $this->puzzlesSorter->sortByFastestPpm($puzzles),
            PlayerResultsSort::SlowestPpm => $this->puzzlesSorter->sortBySlowestPpm($puzzles),
            PlayerResultsSort::Easiest => $this->puzzlesSorter->sortByDifficulty($puzzles, $scores, hardestFirst: false),
            PlayerResultsSort::Hardest => $this->puzzlesSorter->sortByDifficulty($puzzles, $scores, hardestFirst: true),
            default => $this->puzzlesSorter->sortByFastest($puzzles),
        };
    }

    /**
     * @param array<array-key, array<SolvedPuzzle>> $groups
     * @return array<array-key, non-empty-list<SolvedPuzzle>>
     */
    private static function lists(array $groups): array
    {
        $lists = [];

        foreach ($groups as $key => $group) {
            $group = array_values($group);

            if ($group !== []) {
                $lists[$key] = $group;
            }
        }

        return $lists;
    }

    /**
     * One tab's rows in the shown view. A sort that does not apply to the tab (Best rank off the Solo tab, Attempts on
     * Every result) leaves it in the default order
     *
     * @param list<SolvedPuzzle> $puzzles
     * @param array<string, float> $scores
     * @param array<string, PlayerRanking> $ranks
     * @return array<array-key, non-empty-list<SolvedPuzzle>>
     */
    private function tabRows(
        array $puzzles,
        string $tab,
        PlayerResultsView $view,
        PlayerResultsSort $sort,
        PlayerResultsCriteria $criteria,
        array $scores,
        array $ranks,
    ): array {
        $sort = $sort->appliesTo($tab, $view) ? $sort : PlayerResultsSort::DEFAULT;

        if ($view === PlayerResultsView::Every) {
            return $this->everyResultRows($puzzles, $sort, $criteria, $scores);
        }

        return $this->puzzleRows($puzzles, $tab, $sort, $criteria, $scores, $ranks);
    }

    /**
     * By puzzle (one puzzle; on the Pairs and Teams tabs one puzzle and pair/team): every row leads with the player's
     * best attempt - under "first tries" / "unboxed" the best such attempt, a row without one is left out - and the
     * rows are ordered by that attempt
     *
     * @param list<SolvedPuzzle> $puzzles
     * @param array<string, float> $scores
     * @param array<string, PlayerRanking> $ranks every rank (Best rank sort only)
     * @return array<array-key, non-empty-list<SolvedPuzzle>>
     */
    private function puzzleRows(array $puzzles, string $tab, PlayerResultsSort $sort, PlayerResultsCriteria $criteria, array $scores, array $ranks): array
    {
        $groups = [];

        foreach ($puzzles as $puzzle) {
            $groups[$tab === PlayerResultsCriteria::CATEGORY_SOLO ? $puzzle->puzzleId : self::teamRowKey($puzzle)][] = $puzzle;
        }

        $rows = [];

        foreach ($groups as $key => $attempts) {
            usort($attempts, self::bestFirst(...));
            $leading = null;

            foreach ($attempts as $attempt) {
                if (self::matchesAttemptFilters($attempt, $criteria)) {
                    $leading = $attempt;
                    break;
                }
            }

            if ($leading === null) {
                continue;
            }

            $rows[$key] = [$leading, ...array_values(array_filter($attempts, static fn (SolvedPuzzle $other): bool => $other !== $leading))];
        }

        $foldedNames = self::foldedNames($sort, $puzzles);
        uasort($rows, static fn (array $a, array $b): int => self::compare($sort, $a[0], $b[0], count($a), count($b), $scores, $ranks, $foldedNames));

        return $rows;
    }

    /**
     * Every result: one row each - under "first tries" / "unboxed" only such results
     *
     * @param list<SolvedPuzzle> $puzzles
     * @param array<string, float> $scores
     * @return array<array-key, non-empty-list<SolvedPuzzle>>
     */
    private function everyResultRows(array $puzzles, PlayerResultsSort $sort, PlayerResultsCriteria $criteria, array $scores): array
    {
        $puzzles = array_values(array_filter($puzzles, static fn (SolvedPuzzle $puzzle): bool => self::matchesAttemptFilters($puzzle, $criteria)));
        $foldedNames = self::foldedNames($sort, $puzzles);
        usort($puzzles, static fn (SolvedPuzzle $a, SolvedPuzzle $b): int => self::compare($sort, $a, $b, 1, 1, $scores, [], $foldedNames));

        $rows = [];

        foreach ($puzzles as $puzzle) {
            $rows[$puzzle->timeId] = [$puzzle];
        }

        return $rows;
    }

    private static function matchesAttemptFilters(SolvedPuzzle $attempt, PlayerResultsCriteria $criteria): bool
    {
        return ($criteria->onlyFirstTries === false || $attempt->firstAttempt)
            && ($criteria->onlyUnboxed === false || $attempt->unboxed);
    }

    /**
     * The best attempt first: the fastest (a result without a time last), the earlier of equal times, then by id
     */
    private static function bestFirst(SolvedPuzzle $a, SolvedPuzzle $b): int
    {
        return self::nullsLast($a->time, $b->time)
            ?: self::dateOf($a) <=> self::dateOf($b)
            ?: $a->timeId <=> $b->timeId;
    }

    /**
     * Two rows by the sort, on the attempt each row shows - every comparison ends on the puzzle and the result id, so
     * equal rows keep one order from one load to the next
     *
     * @param array<string, float> $scores
     * @param array<string, PlayerRanking> $ranks
     * @param array<string, string> $foldedNames by puzzle id (Name sort only)
     */
    private static function compare(
        PlayerResultsSort $sort,
        SolvedPuzzle $a,
        SolvedPuzzle $b,
        int $attemptsA,
        int $attemptsB,
        array $scores,
        array $ranks,
        array $foldedNames,
    ): int {
        $comparison = match ($sort) {
            PlayerResultsSort::Newest => self::dateOf($b) <=> self::dateOf($a),
            PlayerResultsSort::Oldest => self::dateOf($a) <=> self::dateOf($b),
            PlayerResultsSort::Fastest => self::nullsLast($a->time, $b->time) ?: self::dateOf($a) <=> self::dateOf($b),
            PlayerResultsSort::Slowest => self::nullsLast($a->time, $b->time, descending: true) ?: self::dateOf($a) <=> self::dateOf($b),
            PlayerResultsSort::FastestPpm => self::nullsLast(self::ppm($a), self::ppm($b), descending: true) ?: self::dateOf($a) <=> self::dateOf($b),
            PlayerResultsSort::SlowestPpm => self::nullsLast(self::ppm($a), self::ppm($b)) ?: self::dateOf($a) <=> self::dateOf($b),
            PlayerResultsSort::Name => ($foldedNames[$a->puzzleId] ?? '') <=> ($foldedNames[$b->puzzleId] ?? ''),
            // Unranked last, a larger field first among equal ranks
            PlayerResultsSort::Rank => self::nullsLast($ranks[$a->puzzleId]->rank ?? null, $ranks[$b->puzzleId]->rank ?? null)
                ?: ($ranks[$b->puzzleId]->totalPlayers ?? 0) <=> ($ranks[$a->puzzleId]->totalPlayers ?? 0),
            PlayerResultsSort::Attempts => $attemptsB <=> $attemptsA ?: self::dateOf($b) <=> self::dateOf($a),
            // Not rated yet last both ways, the faster first among equally difficult ones
            PlayerResultsSort::Easiest => self::nullsLast($scores[$a->puzzleId] ?? null, $scores[$b->puzzleId] ?? null)
                ?: self::nullsLast($a->time, $b->time),
            PlayerResultsSort::Hardest => self::nullsLast($scores[$a->puzzleId] ?? null, $scores[$b->puzzleId] ?? null, descending: true)
                ?: self::nullsLast($a->time, $b->time),
        };

        return $comparison ?: $a->puzzleId <=> $b->puzzleId ?: $a->timeId <=> $b->timeId;
    }

    /**
     * Nulls (no time, no PPM, not rated, unranked) last in both directions
     */
    private static function nullsLast(null|int|float $a, null|int|float $b, bool $descending = false): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) - ($b === null ? 1 : 0);
        }

        return $descending ? $b <=> $a : $a <=> $b;
    }

    /**
     * Folded main titles for the Name sort (folded once per puzzle, not per comparison)
     *
     * @param list<SolvedPuzzle> $puzzles
     * @return array<string, string>
     */
    private static function foldedNames(PlayerResultsSort $sort, array $puzzles): array
    {
        if ($sort !== PlayerResultsSort::Name) {
            return [];
        }

        $names = [];

        foreach ($puzzles as $puzzle) {
            $names[$puzzle->puzzleId] ??= SearchText::fold($puzzle->puzzleName);
        }

        return $names;
    }

    private static function ppm(SolvedPuzzle $puzzle): null|float
    {
        if ($puzzle->time === null || $puzzle->time === 0) {
            return null;
        }

        return ($puzzle->piecesCount * 60) / $puzzle->time;
    }

    private static function dateOf(SolvedPuzzle $puzzle): DateTimeImmutable
    {
        return $puzzle->finishedAt ?? $puzzle->trackedAt;
    }

    private static function teamRowKey(SolvedPuzzle $puzzle): string
    {
        return $puzzle->puzzleId . '|' . ($puzzle->teamId ?? $puzzle->timeId);
    }

    /**
     * Brand name => the player's puzzles of the brand, most first
     *
     * @param list<SolvedPuzzle> $all
     * @return array<string, int>
     */
    private static function availableBrands(array $all): array
    {
        $brands = [];
        $seen = [];

        foreach ($all as $puzzle) {
            if (isset($seen[$puzzle->puzzleId])) {
                continue;
            }

            $seen[$puzzle->puzzleId] = true;
            $brands[$puzzle->manufacturerName] = ($brands[$puzzle->manufacturerName] ?? 0) + 1;
        }

        arsort($brands);

        return $brands;
    }

    /**
     * The pairs and teams the player has results with, most results first (at most 30). The template names an unnamed
     * one by its other members, each the way this viewer may see them.
     *
     * @param list<SolvedPuzzle> $groupResults
     * @return list<array{teamId: string, name: null|string, isPair: bool, players: array<Puzzler>, count: int}>
     */
    private static function availableTeams(string $playerId, array $groupResults): array
    {
        $teams = [];

        foreach ($groupResults as $puzzle) {
            if ($puzzle->teamId === null) {
                continue;
            }

            $teams[$puzzle->teamId] ??= [
                'teamId' => $puzzle->teamId,
                'name' => $puzzle->teamName,
                'isPair' => count($puzzle->players ?? []) === 2,
                'players' => array_filter($puzzle->players ?? [], static fn (Puzzler $puzzler): bool => $puzzler->playerId !== $playerId),
                'count' => 0,
            ];
            $teams[$puzzle->teamId]['count']++;
        }

        usort($teams, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($teams, 0, 30);
    }

    /**
     * Piece-count chips the player has at least one result for - plus the active one, so a selection never hides its
     * own chip
     *
     * @param list<SolvedPuzzle> $all
     * @return list<PiecesRange>
     */
    private static function availablePiecePresets(array $all, null|PiecesRange $active): array
    {
        $activeParam = $active?->toParam();

        return array_values(array_filter(PiecesRange::presets(), static function (PiecesRange $preset) use ($all, $activeParam): bool {
            if ($preset->toParam() === $activeParam) {
                return true;
            }

            foreach ($all as $puzzle) {
                if ($preset->contains($puzzle->piecesCount)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Difficulty chips. Members get only the tiers the player has a result in (+ "not rated yet") - plus the selected
     * ones, so a selection never hides its own chip. Everyone else sees all of them, locked.
     *
     * @param list<SolvedPuzzle> $all
     * @param list<string> $selected
     * @param array<string, DifficultyTier> $tiers
     * @return list<array{value: string, tier: null|DifficultyTier}>
     */
    private static function difficultyOptions(array $all, bool $withDifficulty, array $selected, array $tiers): array
    {
        $present = null;

        if ($withDifficulty) {
            $present = array_flip($selected);

            foreach ($all as $puzzle) {
                $present[(string) self::difficultyOf($puzzle, $tiers)] = true;
            }
        }

        return array_values(array_filter(
            DifficultyFilter::options(),
            static fn (array $option): bool => $present === null || isset($present[$option['value']]),
        ));
    }
}
