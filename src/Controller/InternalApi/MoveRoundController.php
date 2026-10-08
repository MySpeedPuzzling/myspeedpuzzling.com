<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Moves a round to another one-time event or edition (`{"competitionId": "…"}`, MoveRoundToCompetition): the round
 * with its puzzles (and their reveal), its table layout and every solving time that belongs to it - their competition
 * changes, their round stays. Answers the round where it is now (`competitionId`, `slug` - `-2`, `-3`, … when the
 * target had its slug - and `resultsCount`). 409, nothing changed: the same competition, participants entered in the
 * round, its stopwatch running, or one of its puzzles already in a round of the same category in the target.
 */
final class MoveRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}/move',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to act as.');
        }

        $round = $this->competitionRoundRepository->get($roundId);

        $input = InternalApiInput::fromRequest($request, ['competitionId']);
        $targetId = $input->id('competitionId', required: true);
        $input->throwIfInvalid();
        assert($targetId !== null);

        // 404 for an unknown target before anything runs
        $target = $this->competitionRepository->get($targetId);

        $this->messageBus->dispatch(new MoveRoundToCompetition(
            roundId: $round->id->toString(),
            competitionId: $round->competition->id->toString(),
            targetCompetitionId: $target->id->toString(),
            actingPlayerId: $this->reviewerPlayerId,
        ));

        return new JsonResponse($this->getAdminCompetitions->round($round->id->toString())->toArray());
    }
}
