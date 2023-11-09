<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ResetStopwatch;
use SpeedPuzzling\Web\Message\ResumeStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ResetStopwatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/stopky/{stopwatchId}/reset', name: 'reset_stopwatch', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        string $stopwatchId,
    ): Response
    {
        $this->messageBus->dispatch(
            new ResetStopwatch(
                Uuid::fromString($stopwatchId),
                $user->getUserIdentifier(),
            )
        );

        return $this->redirectToRoute('stopwatch');
    }
}
