<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlayerPuzzleResultsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetRanking $getRanking,
        readonly private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: '/en/player/{playerId}/puzzle/{puzzleId}/results/{category}',
        name: 'player_puzzle_results',
        requirements: ['category' => 'solo|duo|team'],
    )]
    public function __invoke(string $playerId, string $puzzleId, string $category, Request $request): Response
    {
        $player = $this->getPlayerProfile->byId($playerId);
        $results = $this->getPlayerSolvedPuzzles->byPlayerIdPuzzleIdAndCategory($playerId, $puzzleId, $category);

        if ($results === []) {
            throw $this->createNotFoundException();
        }

        $ranking = null;
        if ($category === 'solo') {
            $ranking = $this->getRanking->ofPuzzleForPlayer($puzzleId, $playerId);
        }

        $templateData = [
            'player' => $player,
            'results' => $results,
            'ranking' => $ranking,
            'category' => $category,
        ];

        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('player-puzzle-results/_modal.html.twig', $templateData);
        }

        return $this->render('player-puzzle-results/detail.html.twig', $templateData);
    }
}
