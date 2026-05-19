<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class RoundStopwatchStateController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
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
        $round = $this->competitionRoundRepository->get($roundId);

        $response = new JsonResponse([
            'status' => $round->stopwatchStatus,
            'startedAt' => $round->stopwatchStartedAt?->format(\DateTimeInterface::ATOM),
            'stoppedAt' => $round->stopwatchStoppedAt?->format(\DateTimeInterface::ATOM),
            'minutesLimit' => $round->minutesLimit,
            'serverNow' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
