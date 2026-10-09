<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UnpublishCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back to draft (UnpublishCompetitionSeries) - only while none of its editions has participants, official results or
 * linked solving times (409 CannotUnpublish naming them otherwise). A draft series hides its editions too.
 */
final class UnpublishSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionSeriesRepository $competitionSeriesRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/series/{seriesId}/unpublish',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $seriesId): Response
    {
        $series = $this->competitionSeriesRepository->get($seriesId);

        $this->messageBus->dispatch(new UnpublishCompetitionSeries($series->id->toString()));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
