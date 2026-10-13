<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Results\LeaderboardSlice;
use SpeedPuzzling\Web\Results\PlayerRatingEntry;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\LeaderboardPosition;
use SpeedPuzzling\Web\Value\LeaderboardWindow;
use SpeedPuzzling\Web\Value\PlayerCardBoard;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The MSP Rating ladder as a "where am I" board (docs/features/xp-levels/ux-polish/design-spec.md §4, §8.4): the top 25, a
 * "⋯ N more" row revealing exactly the rows it counts, the viewer's row with 2 above and 2 below, "Show more" after the
 * last row shown, and the viewer's position line - no numbered pages. One window statement per render.
 */
#[AsLiveComponent]
final class MspRatingLadder
{
    use DefaultActionTrait;

    private const int PIECES_COUNT = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0];

    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    // Members only, like the favorites filter - a guest's or a free player's value is dropped (membersOnlyFilters())
    #[LiveProp(writable: true, url: true)]
    public string $country = '';

    #[LiveProp(writable: true, url: true)]
    public bool $onlyFavorites = false;

    /**
     * The top rows shown and the rows below the viewer's own row (LeaderboardWindow). Not writable: changed only by the
     * actions, and back to the start whenever the list changes ($pagedList)
     */
    #[LiveProp]
    public int $limit = LeaderboardWindow::BOARD_TOP_ROWS;

    #[LiveProp]
    public int $belowOwnRow = LeaderboardWindow::NEIGHBOURS;

    /**
     * The list the revealed rows belong to - the filters and the search. Another list starts from its top again, the
     * same list keeps the rows revealed whatever a request carries. Never reset the rows in a filter's onUpdated hook
     * (or by a hash of the props): Live re-sends a <select data-model> value that differs from the prop with every
     * request (docs/features/puzzle-leaderboard-chart.md, the Live Component gotcha).
     */
    #[LiveProp]
    public string $pagedList = '';

    /**
     * The list as the visitor saw it on the last render - the gap row and "Show more" act on what was shown, so the gap
     * row reveals exactly the rows it counted
     */
    #[LiveProp]
    public int $shownTotal = 0;

    #[LiveProp]
    public null|int $shownOwnPosition = null;

    /** @var null|LeaderboardSlice<PlayerRatingEntry> */
    private null|LeaderboardSlice $slice = null;

    private null|LeaderboardWindow $window = null;

    /**
     * The rows the action of this request revealed (1-based positions) - focus moves to the first of them and a polite
     * live region announces them (templates/leaderboards/_window_announcer.html.twig)
     *
     * @var null|array{from: int, to: int}
     */
    private null|array $revealed = null;

    private null|string $viewerPlayerId = null;

    private bool $showMoreRequested = false;
    private bool $revealGapRequested = false;

    /** @var null|list<array{code: string, name: string}> */
    private null|array $countries = null;

    public function __construct(
        readonly private GetPlayerRatingRanking $getPlayerRatingRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    // The "⋯ N more" row: every row it counts, so the ranking runs on without a break down to the viewer
    #[LiveAction]
    public function revealGap(): void
    {
        $this->revealGapRequested = true;
    }

    // The button under the list: the next rows after the last one shown
    #[LiveAction]
    public function showMore(): void
    {
        $this->showMoreRequested = true;
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $this->membersOnlyFilters($profile !== null && $profile->activeMembership);

        $search = trim($this->search);
        $listKey = implode('|', [$this->country, $this->onlyFavorites ? 'favorites' : '', mb_strtolower($search)]);
        $sameList = $this->pagedList === '' || $this->pagedList === $listKey;

        if ($sameList === false) {
            $this->limit = LeaderboardWindow::BOARD_TOP_ROWS;
            $this->belowOwnRow = LeaderboardWindow::NEIGHBOURS;
        }

        $this->pagedList = $listKey;

        // Board windows (design-spec §8.4): the top 25, the gap row and "Show more" reveal at most 50 rows per tap
        $asked = LeaderboardWindow::board($this->shownTotal, $this->shownOwnPosition, $this->limit, $this->belowOwnRow);
        $this->revealed = null;

        if ($sameList === true && $this->revealGapRequested === true) {
            $this->revealed = $asked->gapReveal();
            $asked = $asked->revealGap();
        }

        if ($sameList === true && $this->showMoreRequested === true) {
            $this->revealed = $asked->showMoreReveal();
            $asked = $asked->showMore();
        }

        $this->limit = $asked->limit();
        $this->belowOwnRow = $asked->belowOwnRow();

        $this->slice = $this->getPlayerRatingRanking->window(
            piecesCount: self::PIECES_COUNT,
            limit: $this->limit,
            above: LeaderboardWindow::NEIGHBOURS,
            below: $this->belowOwnRow,
            viewerPlayerId: $profile?->playerId,
            country: $this->country !== '' ? $this->country : null,
            searchTerm: $search !== '' ? $search : null,
            favoriteOfPlayerId: $this->onlyFavorites ? $profile?->playerId : null,
        );

        $this->viewerPlayerId = $profile?->playerId;
        $this->window = LeaderboardWindow::board($this->slice->total, $this->slice->ownPosition, $this->limit, $this->belowOwnRow);
        $this->shownTotal = $this->slice->total;
        $this->shownOwnPosition = $this->slice->ownPosition;
    }

    /**
     * @return list<PlayerRatingEntry>
     */
    public function getEntries(): array
    {
        return $this->getSlice()->rows;
    }

    /**
     * "⋯ N more" rows: the index of the entry it goes above => the rows it counts
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

    /**
     * The gap row's next reveal ("Show ranks 26–75 of 152"), 1-based positions
     *
     * @return null|array{from: int, to: int}
     */
    public function getGapReveal(): null|array
    {
        return $this->getWindow()->gapReveal();
    }

    // The id of a row's <tr> - the viewer's own row keeps the "Jump to me" target
    public function rowId(PlayerRatingEntry $entry): string
    {
        return $entry->playerId === $this->viewerPlayerId ? 'my-rating-row' : 'rating-row-' . $entry->playerId;
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

        if (!is_int($index)) {
            return null;
        }

        return [
            'focus_id' => $this->rowId($this->getSlice()->rows[$index]),
            'reveal' => $this->revealed,
            'total' => $this->getSlice()->total,
        ];
    }

    public function getTotalCount(): int
    {
        return $this->getSlice()->total;
    }

    public function getRowsBelowCount(): int
    {
        return $this->getWindow()->rowsBelow();
    }

    public function getShowMoreCount(): int
    {
        return $this->getWindow()->showMoreCount();
    }

    // The viewer's line above the list - null when they are not on this list (not rated, private, opted out, filtered out)
    public function getViewerPosition(): null|LeaderboardPosition
    {
        return $this->getSlice()->viewerPosition;
    }

    public function isOwnRowBelowFirstScreen(): bool
    {
        return $this->getWindow()->ownRowBelowFirstScreen();
    }

    /**
     * The player card's context of the list on screen (docs/features/navigation CARD-02): the ladder in the country -
     * not the favorites list, whose ranks are the list's own; a search keeps the list's ranks
     */
    public function getPlayerCardContext(): null|string
    {
        return $this->onlyFavorites ? null : PlayerCardBoard::contextOf(PlayerCardBoard::MSP, self::PIECES_COUNT, $this->getActiveCountry()?->name);
    }

    // The country the list is narrowed to, for "in Czechia" - null on the world list
    public function getActiveCountry(): null|CountryCode
    {
        return CountryCode::fromCode($this->country !== '' ? $this->country : null);
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->country !== '' || $this->onlyFavorites;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function getCountries(): array
    {
        if ($this->countries !== null) {
            return $this->countries;
        }

        $this->countries = [];

        foreach ($this->getPlayerRatingRanking->distinctCountries(self::PIECES_COUNT) as $code) {
            $countryEnum = CountryCode::fromCode($code);

            if ($countryEnum !== null) {
                $this->countries[] = ['code' => $code, 'name' => $countryEnum->value];
            }
        }

        return $this->countries;
    }

    /**
     * The country and favorites filters are a membership feature - the page shows the locked button instead - so a
     * value arriving from the URL is dropped for everybody else
     */
    private function membersOnlyFilters(bool $isMember): void
    {
        if ($isMember === false) {
            $this->country = '';
            $this->onlyFavorites = false;
        }

        if ($this->country !== '' && CountryCode::fromCode($this->country) === null) {
            $this->country = '';
        }
    }

    /**
     * @return LeaderboardSlice<PlayerRatingEntry>
     */
    private function getSlice(): LeaderboardSlice
    {
        if ($this->slice === null) {
            $this->populate();
        }

        assert($this->slice !== null);

        return $this->slice;
    }

    private function getWindow(): LeaderboardWindow
    {
        if ($this->window === null) {
            $this->populate();
        }

        assert($this->window !== null);

        return $this->window;
    }
}
