<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The participants spreadsheet's safety net next to its live updates: `{"version": "…"}`, asked every 30 s while the
 * page is visible - a version other than the page's makes it fetch the state. The event's organisers only, the
 * official results API rules (OfficialResultsApi). An event deleted meanwhile is a JSON 404 for everybody, so the page
 * says it is gone instead of asking forever.
 */
final class ParticipantsSheetVersionController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetParticipantsSheetVersion $getParticipantsSheetVersion,
        private readonly OfficialResultsApi $api,
    ) {
    }

    #[Route(
        path: '/{_locale}/participants-sheet-api/{competitionId}/version',
        name: 'participants_sheet_version',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        $competitionId = $this->competitionRepository->get($competitionId)->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: false);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        return OfficialResultsApi::json([
            'version' => $this->getParticipantsSheetVersion->ofCompetition($competitionId),
        ]);
    }
}
