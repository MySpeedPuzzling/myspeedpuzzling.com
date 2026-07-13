<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetXpLeaderboard;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class XpLeaderboardController extends AbstractController
{
    private const array TABS = ['this-week', 'all-time', 'achievement-points'];

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

        $tab = $request->query->getString('tab', 'this-week');

        if (in_array($tab, self::TABS, true) === false) {
            $tab = 'this-week';
        }

        $country = $request->query->getString('country');
        $country = $country !== '' ? strtolower($country) : null;

        $favoritesOnly = $request->query->getBoolean('favorites') && $profile !== null;
        $favoriteIds = $favoritesOnly ? array_values($profile->favoritePlayers) : null;

        // The AP ladder is for logged-in eyes only (free users may look, §1.7).
        if ($tab === 'achievement-points' && $profile === null) {
            $rows = [];
        } else {
            $rows = match ($tab) {
                'all-time' => $this->getXpLeaderboard->allTime($country, $favoriteIds),
                'achievement-points' => $this->getXpLeaderboard->achievementPoints($country, $favoriteIds),
                default => $this->getXpLeaderboard->thisWeek($country, $favoriteIds),
            };
        }

        $selfRank = null;
        $selfOnBoard = false;

        if ($profile !== null) {
            foreach ($rows as $row) {
                if ($row->playerId === $profile->playerId) {
                    $selfOnBoard = true;
                    break;
                }
            }

            if ($selfOnBoard === false) {
                $selfRank = $this->getXpLeaderboard->selfRank($profile->playerId, $tab);
            }
        }

        return $this->render('xp_leaderboard.html.twig', [
            'tab' => $tab,
            'rows' => $rows,
            'countries' => $this->getXpLeaderboard->countries(),
            'selected_country' => $country,
            'favorites_only' => $favoritesOnly,
            'self_rank' => $selfRank,
            'self_on_board' => $selfOnBoard,
            'viewer' => $profile,
        ]);
    }
}
