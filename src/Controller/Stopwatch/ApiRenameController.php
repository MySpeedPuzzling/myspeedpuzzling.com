<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Stopwatch;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RenameStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ApiRenameController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/api/stopwatch/{stopwatchId}/rename',
        name: 'api_stopwatch_rename',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $stopwatchId, Request $request): JsonResponse
    {
        /** @var array{name?: string|null} $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $name = $data['name'] ?? null;

        $this->messageBus->dispatch(
            new RenameStopwatch(
                Uuid::fromString($stopwatchId),
                $user->getUserIdentifier(),
                $name,
            ),
        );

        return new JsonResponse([
            'ok' => true,
        ]);
    }
}
