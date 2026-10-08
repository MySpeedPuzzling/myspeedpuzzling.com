<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetParticipantsSheetState;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The participants spreadsheet's state (GetParticipantsSheetState - the JSON the page embeds), with a fresh live
 * updates subscription: the page fetches it when its version check finds the sheet changed, when its stream opens
 * again and when the tab comes back. The event's organisers only; the official results API rules (OfficialResultsApi -
 * 401 instead of a login page, 403, `private, no-store`; an unknown or deleted event is a JSON 404).
 */
final class ParticipantsSheetStateController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetParticipantsSheetState $getParticipantsSheetState,
        private readonly OfficialResultsApi $api,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/{_locale}/participants-sheet-api/{competitionId}/state',
        name: 'participants_sheet_state',
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

        return OfficialResultsApi::json($this->getParticipantsSheetState->forCompetition(
            $competitionId,
            $this->retrieveLoggedUserProfile->getProfile()?->playerId,
        ));
    }
}
