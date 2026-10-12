<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Stopwatch;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Message\ResumeStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ApiResumeController extends AbstractController
{
    // An offline queue older than a day is not trusted to know when Continue was tapped
    private const int MAX_TAPPED_MILLISECONDS_AGO = 86_400_000;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/api/stopwatch/{stopwatchId}/resume',
        name: 'api_stopwatch_resume',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $stopwatchId, Request $request): JsonResponse
    {
        /** @var mixed $data */
        $data = json_decode($request->getContent(), true);
        $tappedMillisecondsAgo = is_array($data) && is_int($data['tappedMsAgo'] ?? null)
            ? max(0, min($data['tappedMsAgo'], self::MAX_TAPPED_MILLISECONDS_AGO))
            : 0;

        try {
            $this->messageBus->dispatch(
                new ResumeStopwatch(
                    Uuid::fromString($stopwatchId),
                    $user->getUserIdentifier(),
                    $tappedMillisecondsAgo,
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
