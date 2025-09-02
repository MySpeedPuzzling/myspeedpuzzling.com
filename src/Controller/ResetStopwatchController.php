<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ResetStopwatch;
use SpeedPuzzling\Web\Query\GetStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ResetStopwatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetStopwatch $getStopwatch,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/stopky/{stopwatchId}/reset',
            'en' => '/en/stopwatch/{stopwatchId}/reset',
            'es' => '/es/cronometro/{stopwatchId}/reiniciar',
            'ja' => '/ja/ストップウォッチ/{stopwatchId}/リセット',
            'fr' => '/fr/chronometre/{stopwatchId}/reinitialiser',
            'de' => '/de/stoppuhr/{stopwatchId}/zuruecksetzen',
        ],
        name: 'reset_stopwatch',
    )]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        string $stopwatchId,
    ): Response {
        $stopwatch = $this->getStopwatch->byId($stopwatchId);

        $this->messageBus->dispatch(
            new ResetStopwatch(
                Uuid::fromString($stopwatchId),
                $user->getUserIdentifier(),
            )
        );

        if ($stopwatch->puzzleId !== null) {
            return $this->redirectToRoute('stopwatch_puzzle', [
                'puzzleId' => $stopwatch->puzzleId,
            ]);
        }

        return $this->redirectToRoute('stopwatch');
    }
}
