<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Message\ConvertCompetitionToSeries;
use SpeedPuzzling\Web\Query\GetAdminSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Turns a one-time event into a series (`{"keepAsEdition"?: true, "dropParticipants"?: false}`,
 * ConvertCompetitionToSeries - docs/features/events-page/high-frequency-series.md "The conversion tool"). With
 * `keepAsEdition: true` (the default, like the web button) the event becomes the series' first edition; with `false` the
 * event becomes the series: its solving times become series-level results of it, its old address answers 301 to the
 * series page and the competition is deleted - refused (409, nothing changes) while it has rounds, official results,
 * referees, page sections, marketplace marks or participants (unless `dropParticipants`). 409 for an edition. No
 * reviewer player needed: the series takes the event's creator and approval.
 */
final class ConvertCompetitionToSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetAdminSeries $getAdminSeries,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/convert-to-series',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        // 404 for an unknown competition before anything runs
        $competition = $this->competitionRepository->get($competitionId);

        $input = InternalApiInput::fromRequest($request, ['keepAsEdition', 'dropParticipants']);
        $keepAsEdition = $input->bool('keepAsEdition') ?? true;
        $dropParticipants = $input->bool('dropParticipants') ?? false;
        $input->throwIfInvalid();

        $seriesId = Uuid::uuid7();

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competition->id->toString(),
            seriesId: $seriesId,
            keepAsEdition: $keepAsEdition,
            dropParticipants: $dropParticipants,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $seriesId->toString());

        return new JsonResponse($this->getAdminSeries->detail($seriesId->toString())->toArray(), Response::HTTP_CREATED);
    }
}
