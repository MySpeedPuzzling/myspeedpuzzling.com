<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetXpLeaderboard;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\ProgressBoard;
use SpeedPuzzling\Web\Results\ProgressBoardRow;
use SpeedPuzzling\Web\Services\CountryFilterOptions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\KarmaGroup;
use SpeedPuzzling\Web\Value\KarmaPeriod;
use SpeedPuzzling\Web\Value\LeaderboardPosition;
use SpeedPuzzling\Web\Value\LeaderboardWindow;
use SpeedPuzzling\Web\Value\ProgressLeaderboardTab;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Progression leaderboards (docs/features/xp-levels/launch-2026-10.md, "Leaderboards"): a page of its own per board
 * (ProgressLeaderboardTab - Level, Achievement Points, Karma: each its own URL, title and canonical, next to the Times
 * ladders; the old /players/xp-leaderboard?tab= URLs redirect, LegacyXpLeaderboardRedirectController), the shared
 * leaderboard row. "Where am I" like the puzzle page
 * (docs/features/xp-levels/ux-polish/design-spec.md §4): the top rows, a gap row, the viewer's own row with two
 * neighbours each side, "Show more" after the last row shown (LeaderboardWindow) - one window statement per request.
 *
 * A plain page, not a Live Component: the filters already are a GET form, and the gap row / "Show more" are GET forms
 * too (buttons - crawlers do not submit them) aimed at a Turbo Frame around the list, so the page keeps its scroll
 * position and the revealed rows live only in the request that asked for them.
 */
final class ProgressLeaderboardController extends AbstractController
{
    // The most rows a revealed window may ask for - a bound for hand-made URLs, far above what anybody taps to
    private const int MAX_ROWS = 10_000;

    public function __construct(
        readonly private GetXpLeaderboard $getXpLeaderboard,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zebricek/uroven',
            'en' => '/en/ladder/level',
            'es' => '/es/escalera/nivel',
            'ja' => '/ja/リーダーボード/レベル',
            'fr' => '/fr/classement/niveau',
            'de' => '/de/rangliste/level',
        ],
        name: 'level_leaderboard',
        defaults: ['tab' => 'xp'],
    )]
    #[Route(
        path: [
            'cs' => '/zebricek/body-za-uspechy',
            'en' => '/en/ladder/achievement-points',
            'es' => '/es/escalera/puntos-de-logro',
            'ja' => '/ja/リーダーボード/実績ポイント',
            'fr' => '/fr/classement/points-de-succes',
            'de' => '/de/rangliste/erfolgspunkte',
        ],
        name: 'achievement_points_leaderboard',
        defaults: ['tab' => 'achievement-points'],
    )]
    #[Route(
        path: [
            'cs' => '/zebricek/karma',
            'en' => '/en/ladder/karma',
            'es' => '/es/escalera/karma',
            'ja' => '/ja/リーダーボード/カルマ',
            'fr' => '/fr/classement/karma',
            'de' => '/de/rangliste/karma',
        ],
        name: 'karma_leaderboard',
        defaults: ['tab' => 'karma'],
    )]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        // The board is the route (its own URL), never a query parameter
        $tab = ProgressLeaderboardTab::from($request->attributes->getString('tab'));

        $country = CountryCode::fromCode($request->query->getString('country'))?->name;

        $favoritesOnly = $request->query->getBoolean('favorites') && $profile !== null;
        // The favorites board is "me and the people I follow" - the viewer is on it too
        $favoriteIds = $favoritesOnly ? array_values(array_unique([...array_values($profile->favoritePlayers), $profile->playerId])) : null;

        // Karma (docs/features/karma.md): filtered by kind and period
        $karmaGroup = KarmaGroup::tryFrom($request->query->getString('kind'));
        $karmaPeriod = KarmaPeriod::tryFrom($request->query->getString('period')) ?? KarmaPeriod::AllTime;

        /*
         * The rows revealed by the gap row and "Show more" (design-spec §4). They belong to the list they were revealed
         * on: the buttons' GET forms carry them with the list's filters, the filter form never does - another filter
         * starts from the top rows and the viewer's neighbours again.
         */
        $revealed = $request->query->has('limit') || $request->query->has('below');
        $requested = LeaderboardWindow::board(
            totalRows: 0,
            ownPosition: null,
            limitAsked: $request->query->has('limit') ? min(self::MAX_ROWS, $request->query->getInt('limit')) : null,
            belowOwnRowAsked: $request->query->has('below') ? min(self::MAX_ROWS, $request->query->getInt('below')) : null,
        );

        $loginRequired = $tab->requiresLogin() && $profile === null;
        $board = $loginRequired ? null : $this->getXpLeaderboard->board(
            tab: $tab,
            window: $requested,
            country: $country,
            favoritePlayerIds: $favoriteIds,
            viewerPlayerId: $profile?->playerId,
            karmaGroup: $tab === ProgressLeaderboardTab::Karma ? $karmaGroup : null,
            karmaSince: $tab === ProgressLeaderboardTab::Karma ? $karmaPeriod->since($this->clock->now()) : null,
        );

        $window = LeaderboardWindow::board(
            totalRows: $board->total ?? 0,
            ownPosition: $board?->ownPosition,
            limitAsked: $requested->limit(),
            belowOwnRowAsked: $requested->belowOwnRow(),
        );

        $ownRow = $board?->ownRow();
        $position = $board !== null && $ownRow !== null
            ? LeaderboardPosition::describe(
                rank: $ownRow->rank,
                total: $board->total,
                ownValue: $ownRow->value,
                milestoneValues: $board->milestoneValues(),
                higherIsBetter: true,
            )
            : null;

        // The list's own query parameters - what the gap row and "Show more" carry along with the next window
        $listParams = [
            'country' => $country,
            'favorites' => $favoritesOnly ? 1 : null,
            'kind' => $tab === ProgressLeaderboardTab::Karma ? $karmaGroup?->value : null,
            'period' => $tab === ProgressLeaderboardTab::Karma && $karmaPeriod !== KarmaPeriod::AllTime ? $karmaPeriod->value : null,
        ];

        $entries = $board !== null ? $this->entries($board, $window) : [];

        $response = $this->render('xp_leaderboard.html.twig', [
            'tab' => $tab,
            'entries' => $entries,
            'revealed_now' => $this->revealedNow($request->query->getString('focus'), $entries, $window),
            'not_on_board' => $ownRow === null && $loginRequired === false ? $this->notOnBoard($tab, $profile, $country, $karmaGroup !== null || $karmaPeriod !== KarmaPeriod::AllTime) : null,
            'window' => $window,
            'position' => $position,
            'own_row' => $ownRow,
            'list_params' => $listParams,
            'gap_window' => $window->revealGap(),
            'more_window' => $window->showMore(),
            'login_required' => $loginRequired,
            'countries' => CountryFilterOptions::fromCodes($this->getXpLeaderboard->countries(), $request->getLocale()),
            'selected_country' => $country,
            'favorites_only' => $favoritesOnly,
            'karma_group' => $karmaGroup,
            'karma_period' => $karmaPeriod,
            'karma_filtered' => $tab === ProgressLeaderboardTab::Karma && ($karmaGroup !== null || $karmaPeriod !== KarmaPeriod::AllTime),
            'viewer' => $profile,
        ]);

        if ($revealed) {
            // Crawlers never submit the buttons' forms - should a revealed state still get out, it is no page of its own
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    /**
     * Rows a tap on the gap row or "Show more" just revealed (?focus=26-75, 1-based - the buttons send what they reveal):
     * the first of them gets the focus and the range is announced (design-spec §8.4)
     *
     * @param list<array{row: ProgressBoardRow, hidden_before: int}> $entries
     * @return null|array{focus_id: string, from: int, to: int}
     */
    private function revealedNow(string $focus, array $entries, LeaderboardWindow $window): null|array
    {
        if (preg_match('/^(\d+)-(\d+)$/', $focus, $matches) !== 1) {
            return null;
        }

        $from = (int) $matches[1];
        $to = min((int) $matches[2], $window->totalRows);

        foreach ($entries as $entry) {
            if ($entry['row']->position === $from - 1 && $to >= $from) {
                return ['focus_id' => 'board-row-' . $entry['row']->playerId, 'from' => $from, 'to' => $to];
            }
        }

        return null;
    }

    /**
     * Why a signed-in viewer is not on this board, for the one line that says what to do (design-spec §8.4) - checked
     * in the order the boards leave people out. Null when nothing explains it (a list the viewer just is not on).
     */
    private function notOnBoard(ProgressLeaderboardTab $tab, null|PlayerProfile $viewer, null|string $country, bool $karmaFiltered): null|string
    {
        if ($viewer === null) {
            return 'guest';
        }

        if ($viewer->isPrivateProfile) {
            return 'private';
        }

        if ($tab === ProgressLeaderboardTab::Karma) {
            if ($viewer->isAdmin) {
                return 'admin';
            }

            if ($viewer->karmaOptedOut) {
                return 'karma_opted_out';
            }
        } elseif ($viewer->experienceSystemOptedOut) {
            return 'experience_opted_out';
        }

        if ($tab === ProgressLeaderboardTab::AchievementPoints && $viewer->activeMembership === false) {
            return 'not_member';
        }

        $value = match ($tab) {
            ProgressLeaderboardTab::Xp => $viewer->xpTotal,
            ProgressLeaderboardTab::AchievementPoints => $viewer->achievementPoints,
            ProgressLeaderboardTab::Karma => $viewer->karma,
        };

        if ($value < 1) {
            return $tab->value;
        }

        if ($country !== null && $viewer->countryCode?->name !== $country) {
            return 'other_country';
        }

        return $karmaFiltered ? 'karma_filtered' : null;
    }

    /**
     * The rows the window shows, in list order, each with the rows left out right above it (a gap row there)
     *
     * @return list<array{row: ProgressBoardRow, hidden_before: int}>
     */
    private function entries(ProgressBoard $board, LeaderboardWindow $window): array
    {
        $entries = [];

        foreach ($window->blocks() as $block) {
            $hiddenBefore = $block['hiddenBefore'];

            for ($position = $block['first']; $position <= $block['last']; $position++) {
                $row = $board->rows[$position] ?? null;

                if ($row === null) {
                    continue;
                }

                $entries[] = ['row' => $row, 'hidden_before' => $hiddenBefore];
                $hiddenBefore = 0;
            }
        }

        return $entries;
    }
}
