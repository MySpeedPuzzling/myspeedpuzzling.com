<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A one-time event's or an edition's draft flag goes off (PublishCompetition) - a pending one-time event enters the
 * approval queue then; official results published while it was hidden are told once it is public. An edition of a
 * draft series stays hidden until its series is published. Publishing a published competition changes nothing.
 */
final class PublishCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/publish',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $competition = $this->competitionRepository->get($competitionId);

        $this->messageBus->dispatch(new PublishCompetition($competition->id->toString(), notifyAdmin: false));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
