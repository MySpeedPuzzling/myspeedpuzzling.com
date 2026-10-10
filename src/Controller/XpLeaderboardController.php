<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetXpLeaderboard;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\KarmaGroup;
use SpeedPuzzling\Web\Value\KarmaPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class XpLeaderboardController extends AbstractController
{
    /** Two disciplines, two boards: chase XP, chase Achievement Points, or both - and karma, who helps the database */
    private const array TABS = ['xp', 'achievement-points', 'karma'];

    private const int KARMA_LIMIT = 100;

    public function __construct(
        readonly private GetXpLeaderboard $getXpLeaderboard,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private ClockInterface $clock,
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

        $tab = $request->query->getString('tab', 'xp');

        if (in_array($tab, self::TABS, true) === false) {
            $tab = 'xp';
        }

        $country = $request->query->getString('country');
        $country = $country !== '' ? strtolower($country) : null;

        $favoritesOnly = $request->query->getBoolean('favorites') && $profile !== null;
        $favoriteIds = $favoritesOnly ? array_values($profile->favoritePlayers) : null;

        // Karma (docs/features/karma.md): everybody, free, filtered by kind and period - the viewer's row comes along
        $karmaGroup = KarmaGroup::tryFrom($request->query->getString('kind'));
        $karmaPeriod = KarmaPeriod::tryFrom($request->query->getString('period')) ?? KarmaPeriod::AllTime;
        $karmaSelfRank = null;

        if ($tab === 'karma') {
            $rows = [];

            foreach ($this->getXpLeaderboard->karma($karmaGroup, $karmaPeriod->since($this->clock->now()), $country, $favoriteIds, $profile?->playerId, self::KARMA_LIMIT) as $row) {
                if ($row->rank > self::KARMA_LIMIT) {
                    $karmaSelfRank = ['rank' => $row->rank, 'value' => $row->value];
                } else {
                    $rows[] = $row;
                }
            }
        } elseif ($tab === 'achievement-points' && $profile === null) {
            // The AP ladder is for logged-in eyes only (free users may look, §1.7).
            $rows = [];
        } else {
            $rows = $tab === 'achievement-points'
                ? $this->getXpLeaderboard->achievementPoints($country, $favoriteIds)
                : $this->getXpLeaderboard->xp($country, $favoriteIds);
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
                $selfRank = $tab === 'karma'
                    ? $karmaSelfRank
                    : $this->getXpLeaderboard->selfRank($profile->playerId, $tab);
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
            'karma_group' => $karmaGroup,
            'karma_period' => $karmaPeriod,
            'viewer' => $profile,
        ]);
    }
}
