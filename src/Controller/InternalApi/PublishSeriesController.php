<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The series' draft flag goes off (PublishCompetitionSeries) - its editions keep their own flags; a series still
 * waiting for approval enters the approval queue then. Publishing a published series changes nothing.
 */
final class PublishSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionSeriesRepository $competitionSeriesRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/series/{seriesId}/publish',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $seriesId): Response
    {
        $series = $this->competitionSeriesRepository->get($seriesId);

        $this->messageBus->dispatch(new PublishCompetitionSeries($series->id->toString(), notifyAdmin: false));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
