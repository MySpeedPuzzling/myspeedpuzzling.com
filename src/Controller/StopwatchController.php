<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class StopwatchController extends AbstractController
{
    public function __construct(
        readonly private GetStopwatch $getStopwatch,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/stopky/{stopwatchId}',
            'en' => '/en/stopwatch/{stopwatchId}',
            'es' => '/es/cronometro/{stopwatchId}',
        ],
        name: 'stopwatch',
    )]
    #[Route(
        path: [
            'cs' => '/puzzle-stopky/{puzzleId}',
            'en' => '/en/puzzle-stopwatch/{puzzleId}',
            'es' => '/es/cronometro-rompecabezas/{puzzleId}',
        ],
        name: 'stopwatch_puzzle',
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, null|string $stopwatchId = null, null|string $puzzleId = null): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $activePuzzle = null;
        $activeStopwatch = null;

        if ($stopwatchId !== null) {
            $activeStopwatch = $this->getStopwatch->byId($stopwatchId);
            $puzzleId = $activeStopwatch->puzzleId;
        }

        if ($puzzleId !== null) {
            $activePuzzle = $this->getPuzzleOverview->byId($puzzleId);
        }

        $stopwatches = $this->getStopwatch->allForPlayer($player->playerId);

        // If user has any running stopwatch, redirect to them
        foreach ($stopwatches as $stopwatch) {
            if ($stopwatchId === null && $stopwatch->status === StopwatchStatus::Running) {
                return $this->redirectToRoute('stopwatch', [
                    'stopwatchId' => $stopwatch->stopwatchId,
                ]);
            }
        }

        return $this->render('stopwatch.html.twig', [
            'active_stopwatch' => $activeStopwatch,
            'stopwatches' => $stopwatches,
            'active_puzzle' => $activePuzzle,
        ]);
    }
}
