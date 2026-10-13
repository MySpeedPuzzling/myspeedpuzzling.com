<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetRoundStopwatchState;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A round's stopwatch now (RoundStopwatchState - the shape of every Mercure update on its topic) plus the server's time
 * as late as possible, for the clock offset: `serverNow` (ATOM, whole seconds - the pages loaded before the milliseconds
 * came) and `serverNowMs`. Public like the projector page; never cached.
 */
final class RoundStopwatchStateController extends AbstractController
{
    public function __construct(
        private readonly GetRoundStopwatchState $getRoundStopwatchState,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/round-stopwatch-state/{roundId}',
        name: 'round_stopwatch_state',
        methods: ['GET'],
    )]
    public function __invoke(string $roundId): JsonResponse
    {
        $state = $this->getRoundStopwatchState->forRound($roundId);
        $serverNow = $this->clock->now();

        $response = new JsonResponse([
            ...$state->jsonSerialize(),
            'serverNow' => $serverNow->format(\DateTimeInterface::ATOM),
            'serverNowMs' => RoundClock::milliseconds($serverNow),
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
