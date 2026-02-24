<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Stopwatch;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBePaused;
use SpeedPuzzling\Web\Message\StopStopwatch;
use SpeedPuzzling\Web\Query\GetStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ApiPauseController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetStopwatch $getStopwatch,
    ) {
    }

    #[Route(
        path: '/api/stopwatch/{stopwatchId}/pause',
        name: 'api_stopwatch_pause',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $stopwatchId): JsonResponse
    {
        try {
            $this->messageBus->dispatch(
                new StopStopwatch(
                    Uuid::fromString($stopwatchId),
                    $user->getUserIdentifier(),
                ),
            );
        } catch (HandlerFailedException $handlerFailedException) {
            if (!$handlerFailedException->getPrevious() instanceof StopwatchCouldNotBePaused) {
                throw $handlerFailedException;
            }
        }

        $stopwatch = $this->getStopwatch->byId($stopwatchId);

        return new JsonResponse([
            'ok' => true,
            'totalSeconds' => $stopwatch->totalSeconds,
            'serverTime' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
