<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlayerProfileController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private PuzzlesSorter $puzzlesSorter,
        readonly private GetStatistics $getStatistics,
        readonly private GetRanking $getRanking,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private TranslatorInterface $translator,
        readonly private GetTags $getTags,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/profil-hrace/{playerId}',
            'en' => '/en/player-profile/{playerId}',
        ],
        name: 'player_profile',
        methods: ['GET'],
    )]
    public function __invoke(string $playerId): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('ladder');
        }

        $soloStatistics = $this->getStatistics->soloForPlayer($player->playerId);
        $groupStatistics = $this->getStatistics->inGroupForPlayer($player->playerId);
        $playerStatistics = $soloStatistics->sum($groupStatistics);

        $soloSolvedPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId);
        $duoSolvedPuzzles = $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId);
        $teamSolvedPuzzles = $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId);

        return $this->render('player-profile.html.twig', [
            'player' => $player,
            'solo_results' => $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles),
            'duo_results' => $this->puzzlesSorter->groupPuzzles($duoSolvedPuzzles),
            'team_results' => $this->puzzlesSorter->groupPuzzles($teamSolvedPuzzles),
            'statistics' => $playerStatistics,
            'ranking' => $this->getRanking->allForPlayer($player->playerId),
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
            'tags' => $this->getTags->allGroupedPerPuzzle(),
        ]);
    }
}
