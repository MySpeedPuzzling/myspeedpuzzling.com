<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\StartStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class StartStopwatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zapnout-stopky/{puzzleId}',
            'en' => '/en/start-stopwatch/{puzzleId}',
        ],
        name: 'start_stopwatch',
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, null|string $puzzleId = null): Response
    {
        $stopwatchId = Uuid::uuid7();

        $this->messageBus->dispatch(
            new StartStopwatch(
                $stopwatchId,
                $user->getUserIdentifier(),
                $puzzleId,
            ),
        );

        return $this->redirectToRoute('stopwatch', [
            'stopwatchId' => $stopwatchId,
        ]);
    }
}
