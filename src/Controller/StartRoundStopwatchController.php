<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Start" - now, or resume a stopped stopwatch; then the projector page (AbstractRoundStopwatchController). With
 * "Reveal the puzzles" ticked the round's puzzles waiting for the stopwatch start come out now.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class StartRoundStopwatchController extends AbstractRoundStopwatchController
{
    #[Route(
        path: [
            'cs' => '/spustit-stopky-kola/{roundId}',
            'en' => '/en/start-round-stopwatch/{roundId}',
            'es' => '/es/start-round-stopwatch/{roundId}',
            'ja' => '/ja/start-round-stopwatch/{roundId}',
            'fr' => '/fr/start-round-stopwatch/{roundId}',
            'de' => '/de/start-round-stopwatch/{roundId}',
        ],
        name: 'start_round_stopwatch',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        return $this->act(
            $request,
            $roundId,
            static function () use ($request, $roundId): StartRoundStopwatch {
                $reveal = self::revealedFields($request);

                return new StartRoundStopwatch($roundId, revealPuzzles: $reveal['revealPuzzles'], revealListHash: $reveal['revealListHash']);
            },
            'round_stopwatch',
        );
    }
}
