<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetLastSolvedPuzzle;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerStatistics;
use SpeedPuzzling\Web\Query\GetPuzzleCollection;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetStatistics;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetWjpcParticipants;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlayerProfileController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private PuzzlesSorter $puzzlesSorter,
        readonly private GetPlayerStatistics $getPlayerStatistics,
        readonly private GetRanking $getRanking,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private TranslatorInterface $translator,
        readonly private GetTags $getTags,
        readonly private GetLastSolvedPuzzle $getLastSolvedPuzzle,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetBadges $getBadges,
        readonly private GetPuzzleCollection $getPuzzleCollection,
        readonly private GetWjpcParticipants $getWjpcParticipants,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/profil-hrace/{playerId}',
            'en' => '/en/player-profile/{playerId}',
        ],
        name: 'player_profile',
    )]
    public function __invoke(string $playerId, #[CurrentUser] UserInterface|null $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('ladder');
        }

        $soloStatistics = $this->getPlayerStatistics->solo($player->playerId);
        $groupStatistics = $this->getPlayerStatistics->team($player->playerId);
        $playerStatistics = $soloStatistics->sum($groupStatistics);

        $soloSolvedPuzzles = $this->getPlayerSolvedPuzzles->soloByPlayerId($playerId);
        $duoSolvedPuzzles = $this->getPlayerSolvedPuzzles->duoByPlayerId($playerId);
        $teamSolvedPuzzles = $this->getPlayerSolvedPuzzles->teamByPlayerId($playerId);

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $loggedPlayerRanking = [];

        if ($loggedPlayerProfile !== null) {
            $loggedPlayerRanking = $this->getRanking->allForPlayer($loggedPlayerProfile->playerId);
        }

        return $this->render('player-profile.html.twig', [
            'player' => $player,
            'last_solved_puzzles' => $this->getLastSolvedPuzzle->forPlayer($player->playerId, 20),
            'solo_results' => $this->puzzlesSorter->groupPuzzles($soloSolvedPuzzles),
            'duo_results' => $this->puzzlesSorter->groupPuzzles($duoSolvedPuzzles),
            'team_results' => $this->puzzlesSorter->groupPuzzles($teamSolvedPuzzles),
            'statistics' => $playerStatistics,
            'ranking' => $this->getRanking->allForPlayer($player->playerId),
            'logged_player_ranking' => $loggedPlayerRanking,
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
            'tags' => $this->getTags->allGroupedPerPuzzle(),
            'badges' => $this->getBadges->forPlayer($player->playerId),
            'puzzle_collections' => $this->getPuzzleCollection->forPlayer($player->playerId),
            'wjpc_participant' => $this->getWjpcParticipants->forPlayer($player->playerId),
        ]);
    }
}
