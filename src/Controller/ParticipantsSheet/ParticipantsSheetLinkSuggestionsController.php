<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetParticipantLinkSuggestions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The participants sheet's "Suggestions" (docs/features/competitions-management/participant-linking.md §7.2): who the
 * people of the list without a profile may be - `{"people": [{participant, name, country, candidates: [{tier, player,
 * countryMatches, follows, joinedAt, linkedAt, selfJoined}]}]}` (GetParticipantLinkSuggestions, one statement). Read
 * when the organiser opens the People tab's filter, never part of the sheet's state. Accepting one is a sheet change
 * (`player`, or the merge group of a self-joined row) through `participants_sheet_changes`.
 *
 * The official results API rules like the sheet's other JSON endpoints (OfficialResultsApi): the event's organisers
 * only, 401 instead of a login page, 403, `private, no-store`, an unknown event is a JSON 404.
 */
final class ParticipantsSheetLinkSuggestionsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetParticipantLinkSuggestions $getParticipantLinkSuggestions,
        private readonly OfficialResultsApi $api,
    ) {
    }

    #[Route(
        path: '/{_locale}/participants-sheet-api/{competitionId}/link-suggestions',
        name: 'participants_sheet_link_suggestions',
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
            'people' => $this->getParticipantLinkSuggestions->of($competitionId),
        ]);
    }
}
