<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Browse all puzzlers" (docs/features/players-page/README.md, stream S5): the directory of the world or one country
 * with filters and sorts. The Players page's lists link here for their full version.
 */
final class PlayersDirectoryController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/puzzleri/vsichni',
            'en' => '/en/puzzlers/all',
            'es' => '/es/jugadores/todos',
            'ja' => '/ja/プレイヤー/すべて',
            'fr' => '/fr/joueurs/tous',
            'de' => '/de/puzzler/alle',
        ],
        name: 'players_directory',
    )]
    public function __invoke(Request $request): Response
    {
        return $this->render('players/directory.html.twig', [
            'scope' => CommunityScope::fromQuery($request->query->get('scope')),
        ]);
    }
}
