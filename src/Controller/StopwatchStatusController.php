<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class StopwatchStatusController extends AbstractController
{
    public function __construct(
        private readonly GetStopwatch $getStopwatch,
        private readonly GetPlayerProfile $getPlayerProfile,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/stopky/{stopwatchId}', name: 'stopwatch_status', methods: ['GET'])]
    public function __invoke(string $stopwatchId, #[CurrentUser] User $user): Response
    {
        $userId = $user->getUserIdentifier();

        try {
            $player = $this->getPlayerProfile->byUserId($userId);
        } catch (PlayerNotFound $e) {
            $this->logger->critical('Stopwatch status - could not get player', [
                'user_id' => $userId,
                'exception' => $e,
            ]);

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('stopwatch.html.twig', [
            'active_stopwatch' => $this->getStopwatch->byId($stopwatchId),
            'stopwatches' => $this->getStopwatch->allForPlayer($player->playerId),
        ]);
    }
}
