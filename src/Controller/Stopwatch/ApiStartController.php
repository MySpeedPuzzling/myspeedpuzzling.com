<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Stopwatch;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\StartStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ApiStartController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/api/stopwatch/start',
        name: 'api_stopwatch_start',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, Request $request): JsonResponse
    {
        /** @var array{puzzleId?: string|null} $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $puzzleId = $data['puzzleId'] ?? null;

        $stopwatchId = Uuid::uuid7();

        $this->messageBus->dispatch(
            new StartStopwatch(
                $stopwatchId,
                $user->getUserIdentifier(),
                $puzzleId,
            ),
        );

        return new JsonResponse([
            'stopwatchId' => $stopwatchId->toString(),
            'status' => 'running',
            'serverTime' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
