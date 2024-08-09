<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetFastestGroups;
use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LadderController extends AbstractController
{
    public function __construct(
        readonly private GetFastestPlayers $getFastestPlayers,
        readonly private GetFastestPairs $getFastestPairs,
        readonly private GetFastestGroups $getFastestGroups,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zebricek',
            'en' => '/en/ladder',
        ],
        name: 'ladder',
    )]
    public function __invoke(Request $request): Response
    {
        return $this->render('ladder.html.twig', [
            'fastest_players_500_pieces' => $this->getFastestPlayers->perPiecesCount(500, 10),
            'fastest_players_1000_pieces' => $this->getFastestPlayers->perPiecesCount(1000, 10),
            'fastest_pairs_500_pieces' => $this->getFastestPairs->perPiecesCount(500, 10),
            'fastest_pairs_1000_pieces' => $this->getFastestPairs->perPiecesCount(1000, 10),
            'fastest_groups_500_pieces' => $this->getFastestGroups->perPiecesCount(500, 10),
            'fastest_groups_1000_pieces' => $this->getFastestGroups->perPiecesCount(1000, 10),
        ]);
    }
}
