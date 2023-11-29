<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RegisterUserToPlay;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\PuzzlesSorter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MyProfileController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetStopwatch $getStopwatch,
        readonly private MessageBusInterface $messageBus,
        readonly private LoggerInterface $logger,
        readonly private PuzzlesSorter $puzzlesSorter,
    ) {
    }

    #[Route(path: '/muj-profil', name: 'my_profile', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $userId = $user->getUserIdentifier();

        try {
            $player = $this->getPlayerProfile->byUserId($userId);
        } catch (PlayerNotFound) {
            $this->messageBus->dispatch(
                new RegisterUserToPlay(
                    $userId,
                    $user->getEmail(),
                    $user->getName(),
                )
            );

            try {
                $player = $this->getPlayerProfile->byUserId($userId);
            } catch (PlayerNotFound $e) {
                $this->logger->critical('My profile - could not get player', [
                    'user_id' => $userId,
                    'exception' => $e,
                ]);

                return $this->redirectToRoute('homepage');
            }
        }

        $solvedPuzzles = $this->getPlayerSolvedPuzzles->byPlayerId($player->playerId);

        $soloSolvedPuzzles = $this->puzzlesSorter->groupPuzzles(
            $this->puzzlesSorter->filterSolo($solvedPuzzles),
        );

        $groupSolvedPuzzles = $this->puzzlesSorter->groupPuzzles(
            $this->puzzlesSorter->filterGroup($solvedPuzzles),
        );

        return $this->render('my-profile.html.twig', [
            'player' => $player,
            'solo_puzzles' => $soloSolvedPuzzles,
            'group_puzzles' => $groupSolvedPuzzles,
            'stopwatches' => $this->getStopwatch->allForPlayer($player->playerId),
        ]);
    }
}
