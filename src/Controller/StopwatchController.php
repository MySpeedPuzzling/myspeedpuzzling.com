<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class StopwatchController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetStopwatch $getStopwatch,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/stopky/{stopwatchId}', name: 'stopwatch', methods: ['GET'])]
    public function __invoke(#[CurrentUser] UserInterface $user, null|string $stopwatchId = null): Response
    {
        $userId = $user->getUserIdentifier();

        try {
            $player = $this->getPlayerProfile->byUserId($userId);
        } catch (PlayerNotFound $e) {
            $this->logger->critical('Stopwatch - could not get player', [
                'user_id' => $userId,
                'exception' => $e,
            ]);

            return $this->redirectToRoute('my_profile');
        }

        $activeStopwatch = null;

        if ($stopwatchId !== null) {
            $activeStopwatch = $this->getStopwatch->byId($stopwatchId);
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
        ]);
    }
}
