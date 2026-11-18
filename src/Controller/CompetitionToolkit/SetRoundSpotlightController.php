<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\DisplayControlRefused;
use SpeedPuzzling\Web\Message\SetRoundSpotlight;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\DisplayControlSpotlight;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One tap on the spotlight picker (competition toolkit v2 L2, displays-standings.md §5.3): a JSON POST
 * `{"entryRef": "participant_round:<id>" | "team:<id>" | null}` → 200 `{spotlight: {entryRef, name, table}|null}`; 422
 * `{error: unknown_entry|no_displays}`, 400 `invalid_json`, 404 `not_found`, and OfficialResultsApi's 401/403/415
 * (COMPETITION_TOOLKIT_MANAGE on the round's event as loaded from the database, JSON, the `X-CSRF-Token` header). No
 * confirmation, no display choice - every display of the round shows it at once (a `control` signal after the commit).
 */
final class SetRoundSpotlightController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly OfficialResultsApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/ovladani-obrazovek/kolo/{roundId}/spotlight',
            'en' => '/en/display-control/round/{roundId}/spotlight',
            'es' => '/es/display-control/round/{roundId}/spotlight',
            'ja' => '/ja/display-control/round/{roundId}/spotlight',
            'fr' => '/fr/display-control/round/{roundId}/spotlight',
            'de' => '/de/display-control/round/{roundId}/spotlight',
        ],
        name: 'set_round_spotlight',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        try {
            $competitionId = $this->competitionRoundRepository->get($roundId)->competition->id->toString();
        } catch (CompetitionRoundNotFound) {
            return OfficialResultsApi::error('not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        // The voter on the round's event loaded from the database, never on an id from the request
        $playerId = $this->api->authorise($request, $competitionId, write: true, attribute: CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $entryRef = $body['entryRef'] ?? null;

        if ($entryRef !== null && !is_string($entryRef)) {
            return OfficialResultsApi::error('invalid_json', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $envelope = $this->messageBus->dispatch(new SetRoundSpotlight($competitionId, $roundId, $entryRef, $playerId));
        } catch (DisplayControlRefused $refused) {
            return OfficialResultsApi::error($refused->reason, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CompetitionRoundNotFound) {
            return OfficialResultsApi::error('not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        $spotlight = $envelope->last(HandledStamp::class)?->getResult();
        assert($spotlight === null || $spotlight instanceof DisplayControlSpotlight);

        // After the commit: the round's displays read their state again at once
        $this->displayLiveUpdates->controlChanged($competitionId, $roundId);

        return OfficialResultsApi::json(['spotlight' => $spotlight]);
    }
}
