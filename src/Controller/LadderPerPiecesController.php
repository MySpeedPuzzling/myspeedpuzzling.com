<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetFastestGroups;
use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class LadderPerPiecesController extends AbstractController
{
    public function __construct(
        readonly private GetFastestPlayers $getFastestPlayers,
        readonly private GetFastestPairs $getFastestPairs,
        readonly private GetFastestGroups $getFastestGroups,
    ) {
    }

    #[Route(path: '/zebricek/jednotlivci/500-dilku', name: 'ladder_solo_500_pieces', methods: ['GET'])]
    #[Route(path: '/zebricek/jednotlivci/1000-dilku', name: 'ladder_solo_1000_pieces', methods: ['GET'])]
    #[Route(path: '/zebricek/pary/500-dilku', name: 'ladder_pairs_500_pieces', methods: ['GET'])]
    #[Route(path: '/zebricek/pary/1000-dilku', name: 'ladder_pairs_1000_pieces', methods: ['GET'])]
    #[Route(path: '/zebricek/skupiny/500-dilku', name: 'ladder_groups_500_pieces', methods: ['GET'])]
    #[Route(path: '/zebricek/skupiny/1000-dilku', name: 'ladder_groups_1000_pieces', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        /** @var string $routeName */
        $routeName = $request->attributes->get('_route');

        $solvedPuzzleTimes = match ($routeName) {
            'ladder_solo_500_pieces' => $this->getFastestPlayers->perPiecesCount(500, 100),
            'ladder_solo_1000_pieces' => $this->getFastestPlayers->perPiecesCount(1000, 100),
            'ladder_pairs_500_pieces' => $this->getFastestPairs->perPiecesCount(500, 100),
            'ladder_pairs_1000_pieces' => $this->getFastestPairs->perPiecesCount(1000, 100),
            'ladder_groups_500_pieces' => $this->getFastestGroups->perPiecesCount(500, 100),
            'ladder_groups_1000_pieces' => $this->getFastestGroups->perPiecesCount(1000, 100),
            default => throw $this->createNotFoundException(),
        };

        return $this->render('ladder_per_pieces.html.twig', [
            'solved_puzzle_times' => $solvedPuzzleTimes,
        ]);
    }
}
