<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Services\ViewerCountry;
use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Players page: people discovery for the world or one country (docs/features/players-page/README.md). Every
 * section is its own component that loads its own data; this controller only resolves the scope. The search is the
 * Players:Search live component, which reads `?search=` itself.
 */
final class PlayersController extends AbstractController
{
    public function __construct(
        readonly private GetCommunityScopeStats $getCommunityScopeStats,
        readonly private ViewerCountry $viewerCountry,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzleri',
            'en' => '/en/puzzlers',
            'es' => '/es/jugadores',
            'ja' => '/ja/プレイヤー',
            'fr' => '/fr/joueurs',
            'de' => '/de/puzzler',
        ],
        name: 'players',
    )]
    public function __invoke(Request $request): Response
    {
        return $this->render('players/index.html.twig', [
            'scope' => CommunityScope::fromQuery($request->query->get('scope')),
            'home_country' => $this->viewerCountry->fromProfile(),
            // Most puzzlers first - the order of the country typeahead, World always above them
            'scope_countries' => $this->getCommunityScopeStats->countries(),
        ]);
    }
}
