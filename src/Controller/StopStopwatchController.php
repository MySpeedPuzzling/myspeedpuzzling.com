<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBePaused;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Message\StopStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class StopStopwatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/stopky/{stopwatchId}/stop', name: 'stop_stopwatch', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        string $stopwatchId,
    ): Response
    {
        try {
            $this->messageBus->dispatch(
                new StopStopwatch(
                    Uuid::fromString($stopwatchId),
                    $user->getUserIdentifier(),
                )
            );
        } catch (HandlerFailedException $handlerFailedException) {
            if (!$handlerFailedException->getPrevious() instanceof StopwatchCouldNotBePaused) {
                throw $handlerFailedException;
            }
        }

        return $this->redirectToRoute('stopwatch_status', [
            'stopwatchId' => $stopwatchId,
        ]);
    }
}
