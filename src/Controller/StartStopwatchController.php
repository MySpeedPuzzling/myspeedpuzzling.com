<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\StartStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class StartStopwatchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zapnout-stopky/{puzzleId}',
            'en' => '/en/start-stopwatch/{puzzleId}',
            'es' => '/es/iniciar-cronometro/{puzzleId}',
            'ja' => '/ja/ストップウォッチ開始/{puzzleId}',
            'fr' => '/fr/demarrer-chronometre/{puzzleId}',
            'de' => '/de/stoppuhr-starten/{puzzleId}',
        ],
        name: 'start_stopwatch',
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, null|string $puzzleId = null): Response
    {
        // A puzzle a competition keeps secret answers 404 to everybody but its organisers (SecretPuzzleAccess)
        if ($puzzleId !== null) {
            $this->secretPuzzleAccess->assertVisible($puzzleId);
        }

        $stopwatchId = Uuid::uuid7();

        $this->messageBus->dispatch(
            new StartStopwatch(
                $stopwatchId,
                $user->getUserIdentifier(),
                $puzzleId,
            ),
        );

        return $this->redirectToRoute('stopwatch', [
            'stopwatchId' => $stopwatchId,
        ]);
    }
}
