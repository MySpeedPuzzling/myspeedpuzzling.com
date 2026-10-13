<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Reset" - the stopwatch back to not started (AbstractRoundStopwatchController).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ResetRoundStopwatchController extends AbstractRoundStopwatchController
{
    #[Route(
        path: [
            'cs' => '/resetovat-stopky-kola/{roundId}',
            'en' => '/en/reset-round-stopwatch/{roundId}',
            'es' => '/es/reset-round-stopwatch/{roundId}',
            'ja' => '/ja/reset-round-stopwatch/{roundId}',
            'fr' => '/fr/reset-round-stopwatch/{roundId}',
            'de' => '/de/reset-round-stopwatch/{roundId}',
        ],
        name: 'reset_round_stopwatch',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        return $this->act(
            $request,
            $roundId,
            static fn (): ResetRoundStopwatch => new ResetRoundStopwatch(roundId: $roundId),
            'manage_round_stopwatch',
        );
    }
}
