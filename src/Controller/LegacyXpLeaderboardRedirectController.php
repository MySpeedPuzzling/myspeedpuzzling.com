<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Value\ProgressLeaderboardTab;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The progression boards were one page with ?tab= (xp, achievement-points, karma) under /players/xp-leaderboard. Each
 * board now has its own URL (ProgressLeaderboardController): the old URLs answer with a permanent redirect to the
 * board they showed, keeping their filters (country, favorites, karma kind and period).
 */
final class LegacyXpLeaderboardRedirectController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/hraci/xp-zebricek',
            'en' => '/en/players/xp-leaderboard',
            'es' => '/es/jugadores/xp-clasificacion',
            'ja' => '/ja/プレイヤー/xpランキング',
            'fr' => '/fr/joueurs/xp-classement',
            'de' => '/de/spieler/xp-rangliste',
        ],
        name: 'legacy_xp_leaderboard',
    )]
    public function __invoke(Request $request): Response
    {
        $tab = ProgressLeaderboardTab::tryFrom($request->query->getString('tab')) ?? ProgressLeaderboardTab::Xp;

        $query = $request->query->all();
        unset($query['tab']);

        return new RedirectResponse($this->generateUrl($tab->routeName(), $query), Response::HTTP_MOVED_PERMANENTLY);
    }
}
