<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetXpLeaderboard;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\XpLeaderboardRow;
use SpeedPuzzling\Web\Services\CountryFilterOptions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\ProgressLeaderboardTab;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Progression leaderboards (docs/features/xp-levels/launch-2026-10.md, "Leaderboards"): one page, a tab per board
 * (ProgressLeaderboardTab), the shared leaderboard row, the viewer's own row pinned under the list - on the same
 * board the list shows, filters included.
 */
final class XpLeaderboardController extends AbstractController
{
    public function __construct(
        readonly private GetXpLeaderboard $getXpLeaderboard,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/hraci/xp-zebricek',
            'en' => '/en/players/xp-leaderboard',
            'es' => '/es/jugadores/xp-clasificacion',
            'ja' => '/ja/プレイヤー/xpランキング',
            'fr' => '/fr/joueurs/xp-classement',
            'de' => '/de/spieler/xp-rangliste',
        ],
        name: 'xp_leaderboard',
    )]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $tab = ProgressLeaderboardTab::tryFrom($request->query->getString('tab', 'xp')) ?? ProgressLeaderboardTab::Xp;

        $country = $request->query->getString('country');
        $country = $country !== '' ? strtolower($country) : null;

        $favoritesOnly = $request->query->getBoolean('favorites') && $profile !== null;
        // The favorites board is "me and the people I follow" - the viewer is on it too
        $favoriteIds = $favoritesOnly ? array_values(array_unique([...array_values($profile->favoritePlayers), $profile->playerId])) : null;

        $loginRequired = $tab->requiresLogin() && $profile === null;
        $rows = $loginRequired ? [] : $this->rows($tab, $country, $favoriteIds);

        $selfRow = null;

        if ($profile !== null && $loginRequired === false && $this->isOnRows($rows, $profile->playerId) === false) {
            $selfRank = $this->getXpLeaderboard->selfRank($profile->playerId, $tab->value, $country, $favoriteIds);

            if ($selfRank !== null) {
                $selfRow = $this->selfRow($profile, $selfRank);
            }
        }

        return $this->render('xp_leaderboard.html.twig', [
            'tab' => $tab,
            'tabs' => ProgressLeaderboardTab::cases(),
            'rows' => $rows,
            'login_required' => $loginRequired,
            'countries' => CountryFilterOptions::fromCodes($this->getXpLeaderboard->countries(), $request->getLocale()),
            'selected_country' => $country,
            'favorites_only' => $favoritesOnly,
            'self_row' => $selfRow,
            'viewer' => $profile,
        ]);
    }

    /**
     * @param null|list<string> $favoriteIds
     * @return list<XpLeaderboardRow>
     */
    private function rows(ProgressLeaderboardTab $tab, null|string $country, null|array $favoriteIds): array
    {
        return match ($tab) {
            ProgressLeaderboardTab::Xp => $this->getXpLeaderboard->xp($country, $favoriteIds),
            ProgressLeaderboardTab::AchievementPoints => $this->getXpLeaderboard->achievementPoints($country, $favoriteIds),
        };
    }

    /**
     * @param list<XpLeaderboardRow> $rows
     */
    private function isOnRows(array $rows, string $playerId): bool
    {
        foreach ($rows as $row) {
            if ($row->playerId === $playerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{rank: int, value: int, level: int} $selfRank
     */
    private function selfRow(PlayerProfile $profile, array $selfRank): XpLeaderboardRow
    {
        return new XpLeaderboardRow(
            rank: $selfRank['rank'],
            playerId: $profile->playerId,
            playerName: $profile->playerName,
            playerCode: $profile->code,
            countryCode: $profile->countryCode,
            avatar: $profile->avatar,
            value: $selfRank['value'],
            level: $selfRank['level'],
        );
    }
}
