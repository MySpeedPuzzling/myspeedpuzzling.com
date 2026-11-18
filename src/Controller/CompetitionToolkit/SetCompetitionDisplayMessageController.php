<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\DisplayControlRefused;
use SpeedPuzzling\Web\Message\SetCompetitionDisplayMessage;
use SpeedPuzzling\Web\Results\DisplayControlMessage;
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
 * Sets or clears the live message of an event's displays (competition toolkit v2 L1, displays-standings.md §5.3): a
 * JSON POST `{"text": "Break - next round 14:30"}` (`null` or "" clears it) → 200 `{message, recentMessages}`; 422
 * `{error: too_long|no_displays}`, 400 `invalid_json`, 404 `not_found`, and OfficialResultsApi's 401/403/415
 * (COMPETITION_TOOLKIT_MANAGE on the event, JSON, the `X-CSRF-Token` header). The displays fetch at once after the
 * commit (a `control` signal).
 */
final class SetCompetitionDisplayMessageController extends AbstractController
{
    public function __construct(
        private readonly OfficialResultsApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/ovladani-obrazovek/{competitionId}/zprava',
            'en' => '/en/display-control/{competitionId}/message',
            'es' => '/es/display-control/{competitionId}/message',
            'ja' => '/ja/display-control/{competitionId}/message',
            'fr' => '/fr/display-control/{competitionId}/message',
            'de' => '/de/display-control/{competitionId}/message',
        ],
        name: 'set_display_message',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        $playerId = $this->api->authorise($request, $competitionId, write: true, attribute: CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $text = $body['text'] ?? null;

        if ($text !== null && !is_string($text)) {
            return OfficialResultsApi::error('invalid_json', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $envelope = $this->messageBus->dispatch(new SetCompetitionDisplayMessage($competitionId, $text, $playerId));
        } catch (DisplayControlRefused $refused) {
            return OfficialResultsApi::error($refused->reason, JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CompetitionNotFound) {
            return OfficialResultsApi::error('not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        $result = $envelope->last(HandledStamp::class)?->getResult();
        assert($result instanceof DisplayControlMessage);

        // After the commit: every open display of the event reads its state again at once
        $this->displayLiveUpdates->controlChanged($competitionId);

        return OfficialResultsApi::json([
            'message' => $result->message,
            'recentMessages' => $result->recentMessages,
        ]);
    }
}
