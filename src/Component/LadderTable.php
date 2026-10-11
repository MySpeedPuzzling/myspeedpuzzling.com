<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetFastestGroups;
use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Results\LeaderboardSlice;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\ResolveDifficultyTiers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\LeaderboardPosition;
use SpeedPuzzling\Web\Value\LeaderboardWindow;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A Times board (fastest solo / pair / team times of a piece count), "where am I" on every one
 * (docs/features/xp-levels/ux-polish/design-spec.md §4), loaded by one window statement:
 *  - the full board (`fullBoard`, /ladder/{type}/{pieces}): the top 25, a "⋯ N more" row revealing exactly the rows it
 *    counts, the viewer's row with 2 above and 2 below, "Show more" after the last row shown, the position line;
 *  - the /ladder overview's top 10: the viewer's own row after a "⋯" when they are further down, and a link to the full
 *    board around them - in the same statement, so the table costs no extra query.
 * On the pair and team boards the viewer's best pair/team is their row, like on the puzzle page.
 */
#[AsLiveComponent]
final class LadderTable
{
    use DefaultActionTrait;

    // Where the full board's own row sits - the overview's "Full board, around you" link jumps to it
    public const string OWN_ROW_ANCHOR = 'leaderboard-me';

    #[LiveProp]
    public string $type = 'solo';

    #[LiveProp]
    public int $piecesCount = 500;

    // The top rows shown. On the full board changed only by the actions (type, pieces and country never change here,
    // so the revealed rows always belong to the list on screen)
    #[LiveProp]
    public int $limit = 10;

    #[LiveProp]
    public null|string $countryCode = null;

    #[LiveProp]
    public bool $fullBoard = false;

    #[LiveProp]
    public int $belowOwnRow = LeaderboardWindow::NEIGHBOURS;

    // The list as the visitor saw it on the last render - the gap row and "Show more" act on what was shown
    #[LiveProp]
    public int $shownTotal = 0;

    #[LiveProp]
    public null|int $shownOwnPosition = null;

    /** @var null|LeaderboardSlice<SolvedPuzzle> */
    private null|LeaderboardSlice $slice = null;

    private null|LeaderboardWindow $window = null;

    /** @var null|array<string, DifficultyTier> */
    private null|array $difficultyTiers = null;

    private bool $difficultyTiersResolved = false;

    /**
     * The rows the action of this request revealed (1-based positions), for the focus + announcement
     *
     * @var null|array{from: int, to: int}
     */
    private null|array $revealed = null;

    private bool $showMoreRequested = false;
    private bool $revealGapRequested = false;

    public function __construct(
        readonly private GetFastestPlayers $getFastestPlayers,
        readonly private GetFastestPairs $getFastestPairs,
        readonly private GetFastestGroups $getFastestGroups,
        readonly private ResolveDifficultyTiers $resolveDifficultyTiers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    // The "⋯ N more" row of the full board: every row it counts, down to the viewer's rows
    #[LiveAction]
    public function revealGap(): void
    {
        $this->revealGapRequested = true;
    }

    // "Show more" under the full board: the next rows after the last one shown
    #[LiveAction]
    public function showMore(): void
    {
        $this->showMoreRequested = true;
    }

    /**
     * The full board loads before rendering: its window is stored in LiveProps, which are written into the component's
     * attributes before the template runs. The overview's tables have no state to keep and load lazily from the template
     * (two of them are deferred - mounting must not run their statement for the placeholder).
     */
    #[PostMount]
    public function loadFullBoard(): void
    {
        if ($this->fullBoard) {
            $this->getSlice();
        }
    }

    // A Live request (an action, or a deferred table): the rows are loaded after the action ran
    #[PreReRender]
    public function reload(): void
    {
        $this->slice = null;
        $this->window = null;
        $this->difficultyTiers = null;
        $this->difficultyTiersResolved = false;
        $this->loadFullBoard();
    }

    /**
     * @return list<SolvedPuzzle>
     */
    public function getItems(): array
    {
        return $this->getSlice()->rows;
    }

    /**
     * Shared rank of every row: equal times share the place
     *
     * @return list<int>
     */
    public function getRanks(): array
    {
        return $this->getSlice()->ranks;
    }

    /**
     * Rows left out right above a row (index => count): the full board's "⋯ N more" button, the overview's "⋯"
     *
     * @return array<int, int>
     */
    public function getGapsBefore(): array
    {
        $gapsByPosition = $this->getWindow()->gapsBefore();
        $gaps = [];

        foreach ($this->getSlice()->positions as $index => $position) {
            if (isset($gapsByPosition[$position])) {
                $gaps[$index] = $gapsByPosition[$position];
            }
        }

        return $gaps;
    }

    // Index of the viewer's own row (their best pair/team) among the items - null when they are not on the board
    public function getOwnRowIndex(): null|int
    {
        $ownPosition = $this->getSlice()->ownPosition;

        if ($ownPosition === null) {
            return null;
        }

        $index = array_search($ownPosition, $this->getSlice()->positions, true);

        return is_int($index) ? $index : null;
    }

    // The overview: the viewer stands below its top rows, so the link to the full board goes to them
    public function isOwnRowBeyondTop(): bool
    {
        return $this->getWindow()->ownRowBeyondLimit();
    }

    public function isOwnRowBelowFirstScreen(): bool
    {
        return $this->getWindow()->ownRowBelowFirstScreen();
    }

    public function getViewerPosition(): null|LeaderboardPosition
    {
        return $this->getSlice()->viewerPosition;
    }

    /**
     * Why the viewer has no row on the full board, for the one line saying what to do (design-spec §8.4): 'guest',
     * 'private' (solo boards are closed to private profiles), 'no_time' - or null when they are on it, or when the
     * country on screen is not theirs (nothing to explain)
     */
    public function getNotOnBoardReason(): null|string
    {
        if ($this->fullBoard === false || $this->getSlice()->ownPosition !== null) {
            return null;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return 'guest';
        }

        if ($profile->isPrivateProfile && $this->type === 'solo') {
            return 'private';
        }

        $country = $this->getCountry();

        if ($country !== null && $profile->country !== $country->name) {
            return null;
        }

        return 'no_time';
    }

    /**
     * The gap row's next reveal ("Show ranks 26–75 of 1,289"), 1-based positions
     *
     * @return null|array{from: int, to: int}
     */
    public function getGapReveal(): null|array
    {
        return $this->getWindow()->gapReveal();
    }

    // The id of a row's <tr>: the viewer's own row is the anchor of "Jump to me" and the overview's link
    public function rowId(int $index): string
    {
        if ($index === $this->getOwnRowIndex()) {
            return self::OWN_ROW_ANCHOR;
        }

        return 'ladder-row-' . ($this->getSlice()->rows[$index]->timeId ?? $index);
    }

    /**
     * After a tap on the gap row or "Show more": the first new row to focus and what to announce - null otherwise
     *
     * @return null|array{focus_id: string, reveal: array{from: int, to: int}, total: int}
     */
    public function getRevealAnnouncement(): null|array
    {
        if ($this->revealed === null) {
            return null;
        }

        $index = array_search($this->revealed['from'] - 1, $this->getSlice()->positions, true);

        return is_int($index) ? ['focus_id' => $this->rowId($index), 'reveal' => $this->revealed, 'total' => $this->getSlice()->total] : null;
    }

    public function getTotalCount(): int
    {
        return $this->getSlice()->total;
    }

    public function getRowsBelowCount(): int
    {
        return $this->fullBoard ? $this->getWindow()->rowsBelow() : 0;
    }

    public function getShowMoreCount(): int
    {
        return $this->getWindow()->showMoreCount();
    }

    // The full board of this table - the overview's "Full board, around you"
    public function getFullBoardRoute(): string
    {
        $type = in_array($this->type, ['pairs', 'groups'], true) ? $this->type : 'solo';

        return 'ladder_' . $type . '_' . $this->piecesCount . '_pieces' . ($this->getCountry() !== null ? '_country' : '');
    }

    /**
     * @return array<string, string>
     */
    public function getFullBoardRouteParameters(): array
    {
        $country = $this->getCountry();

        return $country !== null ? ['countryCode' => $country->name] : [];
    }

    public function getCountry(): null|CountryCode
    {
        return CountryCode::fromCode($this->countryCode);
    }

    /**
     * Difficulty tier of the rows' puzzles for the thumbnails' corner - members only, null for everyone else.
     *
     * @return null|array<string, DifficultyTier>
     */
    public function getDifficultyTiers(): null|array
    {
        if ($this->difficultyTiersResolved === false) {
            $this->difficultyTiers = $this->resolveDifficultyTiers->forViewer(
                $this->retrieveLoggedUserProfile->getProfile(),
                array_map(static fn (SolvedPuzzle $item): string => $item->puzzleId, $this->getItems()),
            );
            $this->difficultyTiersResolved = true;
        }

        return $this->difficultyTiers;
    }

    /**
     * Gaps of every row like on the puzzle leaderboard: to the fastest time of this board and to the closest faster
     * time - the latter only when that is not the fastest one (the first gap says it). The closest faster time comes
     * from the whole board, so a row right after a gap row measures against the hidden row above it.
     *
     * @return list<array{leader: null|int, faster: null|int}>
     */
    public function getGaps(): array
    {
        $items = $this->getItems();
        $closestBetter = $this->getSlice()->closestBetter;
        // Position 0 is always loaded (the top rows come first), so the first row is the fastest time of the board
        $leaderTime = $items[0]->time ?? null;
        $gaps = [];

        foreach ($items as $index => $item) {
            $time = $item->time;
            $closestFaster = $closestBetter[$index] ?? null;

            $gaps[] = [
                'leader' => $time !== null && $leaderTime !== null && $time > $leaderTime ? $time - $leaderTime : null,
                'faster' => $time !== null && $closestFaster !== null && $closestFaster !== $leaderTime ? $time - $closestFaster : null,
            ];
        }

        return $gaps;
    }

    /**
     * @return LeaderboardSlice<SolvedPuzzle>
     */
    private function getSlice(): LeaderboardSlice
    {
        if ($this->slice !== null) {
            return $this->slice;
        }

        $asked = $this->windowOf($this->shownTotal, $this->shownOwnPosition);
        $this->revealed = null;

        if ($this->fullBoard && $this->revealGapRequested) {
            $this->revealed = $asked->gapReveal();
            $asked = $asked->revealGap();
        }

        if ($this->fullBoard && $this->showMoreRequested) {
            $this->revealed = $asked->showMoreReveal();
            $asked = $asked->showMore();
        }

        $this->limit = $asked->limit();
        $this->belowOwnRow = $asked->belowOwnRow();

        $country = $this->getCountry();
        $viewerPlayerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;
        $query = match ($this->type) {
            'pairs' => $this->getFastestPairs,
            'groups' => $this->getFastestGroups,
            default => $this->getFastestPlayers,
        };

        $this->slice = $query->window($this->piecesCount, $country, $this->limit, $asked->neighbours, $this->belowOwnRow, $viewerPlayerId);
        $this->window = $this->windowOf($this->slice->total, $this->slice->ownPosition);
        $this->shownTotal = $this->slice->total;
        $this->shownOwnPosition = $this->slice->ownPosition;

        return $this->slice;
    }

    /**
     * Board windows (design-spec §8.4): the full board's top 25 with the viewer's rows, the gap row and "Show more"
     * revealing at most 50 rows per tap; the overview's top 10 and the viewer's own row alone
     */
    private function windowOf(int $totalRows, null|int $ownPosition): LeaderboardWindow
    {
        if ($this->fullBoard) {
            return LeaderboardWindow::board($totalRows, $ownPosition, $this->limit, $this->belowOwnRow);
        }

        return LeaderboardWindow::board($totalRows, $ownPosition, $this->limit, 0, topRows: LeaderboardWindow::OVERVIEW_TOP_ROWS, neighbours: 0);
    }

    private function getWindow(): LeaderboardWindow
    {
        $this->getSlice();

        assert($this->window !== null);

        return $this->window;
    }
}
