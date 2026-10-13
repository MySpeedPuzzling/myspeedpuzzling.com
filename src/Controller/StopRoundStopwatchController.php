<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Stop" - the end of an online round, a pause in person (AbstractRoundStopwatchController).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class StopRoundStopwatchController extends AbstractRoundStopwatchController
{
    #[Route(
        path: [
            'cs' => '/zastavit-stopky-kola/{roundId}',
            'en' => '/en/stop-round-stopwatch/{roundId}',
            'es' => '/es/stop-round-stopwatch/{roundId}',
            'ja' => '/ja/stop-round-stopwatch/{roundId}',
            'fr' => '/fr/stop-round-stopwatch/{roundId}',
            'de' => '/de/stop-round-stopwatch/{roundId}',
        ],
        name: 'stop_round_stopwatch',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        return $this->act(
            $request,
            $roundId,
            static fn (): StopRoundStopwatch => new StopRoundStopwatch(roundId: $roundId),
            'manage_round_stopwatch',
        );
    }
}
