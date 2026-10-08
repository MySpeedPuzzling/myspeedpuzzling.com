<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\CoPuzzlerSearchRows;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The participants spreadsheet's profile typeahead (link a participant to a MySpeedPuzzling profile): players matching
 * `?query=` (2+ characters) in the co-puzzler search shape (CoPuzzlerSearchRows, `hidden` = private to the viewer, found
 * by their exact code only). Organiser tooling (docs/features/player-blocklist.md rule 7): players the organiser blocked
 * are found too - blocking somebody must not make them unassignable at an event you run - unlike the site's
 * `player_search_autocomplete`. The event's organisers only, the official results API rules (OfficialResultsApi - 401
 * instead of a login page, 403, `private, no-store`; an unknown event is a JSON 404).
 */
final class ParticipantsSheetPlayerSearchController extends AbstractController
{
    public const int MIN_QUERY_LENGTH = 2;
    public const int LIMIT = 15;

    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly SearchPlayers $searchPlayers,
        private readonly CoPuzzlerSearchRows $coPuzzlerSearchRows,
        private readonly OfficialResultsApi $api,
    ) {
    }

    #[Route(
        path: '/{_locale}/participants-sheet-api/{competitionId}/player-search',
        name: 'participants_sheet_player_search',
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

        $query = trim($request->query->getString('query'));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return OfficialResultsApi::json([]);
        }

        return OfficialResultsApi::json($this->coPuzzlerSearchRows->of(
            $this->searchPlayers->fulltext($query, self::LIMIT, includeHidden: true),
        ));
    }
}
