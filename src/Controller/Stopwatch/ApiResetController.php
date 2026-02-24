<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Stopwatch;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ResetStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ApiResetController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/api/stopwatch/{stopwatchId}/reset',
        name: 'api_stopwatch_reset',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $stopwatchId): JsonResponse
    {
        $this->messageBus->dispatch(
            new ResetStopwatch(
                Uuid::fromString($stopwatchId),
                $user->getUserIdentifier(),
            ),
        );

        return new JsonResponse([
            'ok' => true,
        ]);
    }
}
