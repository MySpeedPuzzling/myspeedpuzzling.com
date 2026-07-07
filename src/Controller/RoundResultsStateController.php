<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RoundResultMember;
use SpeedPuzzling\Web\Results\RoundResultRow;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RoundResultsStateController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetRoundResults $getRoundResults,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/round-results-state/{roundId}',
        name: 'round_results_state',
        methods: ['GET'],
    )]
    public function __invoke(string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        $response = new JsonResponse([
            'round' => [
                'id' => $round->id->toString(),
                'name' => $round->name,
                'category' => $round->category->value,
                'minutesLimit' => $round->minutesLimit,
                'resultsPublished' => $round->areResultsPublished(),
                'stopwatchStatus' => $round->stopwatchStatus,
                'stopwatchStartedAt' => $round->stopwatchStartedAt?->format(\DateTimeInterface::ATOM),
            ],
            'results' => array_map(
                static fn (RoundResultRow $row): array => [
                    'resultId' => $row->resultId,
                    'rank' => $row->rank,
                    'entrantName' => $row->entrantName,
                    'secondsToSolve' => $row->secondsToSolve,
                    'missingPieces' => $row->missingPieces,
                    'participantId' => $row->participantId,
                    'teamId' => $row->teamId,
                    'members' => array_map(
                        static fn (RoundResultMember $member): array => [
                            'name' => $member->name,
                            'playerName' => $member->playerName,
                        ],
                        $row->members,
                    ),
                ],
                $this->getRoundResults->standings($roundId),
            ),
            'entrantsWithoutResult' => $this->getRoundResults->entrantsWithoutResult($roundId),
            'serverNow' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
