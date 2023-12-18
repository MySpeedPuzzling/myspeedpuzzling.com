<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PlayerProfileController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private PuzzlesSorter $puzzlesSorter,
        readonly private GetStatistics $getStatistics,
        readonly private GetRanking $getRanking,
    ) {
    }

    #[Route(path: '/profil-hrace/{playerId}', name: 'player_profile', methods: ['GET'])]
    public function __invoke(string $playerId): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', 'Hráč, kterého jste zkoušeli hledat, u nás není!');

            return $this->redirectToRoute('ladder');
        }

        $soloStatistics = $this->getStatistics->soloForPlayer($player->playerId);
        $groupStatistics = $this->getStatistics->inGroupForPlayer($player->playerId);
        $playerStatistics = $soloStatistics->sum($groupStatistics);

        $soloSolvedPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId);
        $groupSolvedPuzzles = $this->getPlayerSolvedPuzzles->inGroupByPlayerId($player->playerId);

        return $this->render('player-profile.html.twig', [
            'player' => $player,
            'solo_puzzles' => $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles),
            'group_puzzles' => $groupSolvedPuzzles,
            'statistics' => $playerStatistics,
            'ranking' => $this->getRanking->allForPlayer($player->playerId),
        ]);
    }
}
