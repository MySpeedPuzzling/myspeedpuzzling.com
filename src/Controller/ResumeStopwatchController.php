<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Message\ResumeStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ResumeStopwatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/stopky/{stopwatchId}/pokracovat',
            'en' => '/en/stopwatch/{stopwatchId}/resume',
            'es' => '/es/cronometro/{stopwatchId}/continuar',
            'ja' => '/ja/ストップウォッチ/{stopwatchId}/再開',
            'fr' => '/fr/chronometre/{stopwatchId}/reprendre',
            'de' => '/de/stoppuhr/{stopwatchId}/fortsetzen',
        ],
        name: 'resume_stopwatch',
    )]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        string $stopwatchId,
    ): Response {
        try {
            $this->messageBus->dispatch(
                new ResumeStopwatch(
                    Uuid::fromString($stopwatchId),
                    $user->getUserIdentifier(),
                )
            );
        } catch (HandlerFailedException $handlerFailedException) {
            if (!$handlerFailedException->getPrevious() instanceof StopwatchCouldNotBeResumed) {
                throw $handlerFailedException;
            }
        }

        return $this->redirectToRoute('stopwatch', [
            'stopwatchId' => $stopwatchId,
        ]);
    }
}
