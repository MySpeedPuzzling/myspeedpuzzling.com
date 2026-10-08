<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UnpublishCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back to draft (UnpublishCompetition) - only while nobody joined it and no official result or solving time is linked
 * to it (409 CannotUnpublish naming what blocks it).
 */
final class UnpublishCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/unpublish',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $competition = $this->competitionRepository->get($competitionId);

        $this->messageBus->dispatch(new UnpublishCompetition($competition->id->toString()));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
