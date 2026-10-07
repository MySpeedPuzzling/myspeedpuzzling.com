<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deletes a competition (an event or a series edition) with its rounds, puzzle assignments, teams and participants -
 * only while nobody has a result in it (409 otherwise, CompetitionHasResults). For empty leftovers, e.g. an edition
 * created twice. Secret puzzles of its rounds stay hidden (DeleteCompetitionHandler re-syncs them).
 */
final class DeleteCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['DELETE'],
    )]
    public function __invoke(string $competitionId): Response
    {
        // 404 for an unknown competition before anything runs
        $competition = $this->competitionRepository->get($competitionId);

        $this->messageBus->dispatch(new DeleteCompetition(
            competitionId: $competition->id->toString(),
            refuseWhenItHasResults: true,
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
