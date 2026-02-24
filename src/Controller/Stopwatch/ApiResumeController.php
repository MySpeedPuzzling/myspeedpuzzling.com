<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Stopwatch;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Message\ResumeStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ApiResumeController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/api/stopwatch/{stopwatchId}/resume',
        name: 'api_stopwatch_resume',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $stopwatchId): JsonResponse
    {
        try {
            $this->messageBus->dispatch(
                new ResumeStopwatch(
                    Uuid::fromString($stopwatchId),
                    $user->getUserIdentifier(),
                ),
            );
        } catch (HandlerFailedException $handlerFailedException) {
            if (!$handlerFailedException->getPrevious() instanceof StopwatchCouldNotBeResumed) {
                throw $handlerFailedException;
            }
        }

        return new JsonResponse([
            'ok' => true,
            'serverTime' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
