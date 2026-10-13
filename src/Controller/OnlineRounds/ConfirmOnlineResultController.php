<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineResultRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\ConfirmOnlineResult;
use SpeedPuzzling\Web\Results\ConfirmedOnlineResult;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReview;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reviewer confirms a participant's online result (contract §5 `online_review_confirm`, ConfirmOnlineResult):
 * `{"momentMs": <epoch ms>, "source": "button" | "reviewer", "pieces": null, "seenResult": …}` - a finish moment the
 * phone recorded (a tap, or a frame of its burst) - or `{"momentMs": null, "source": "reviewer", "pieces": <placed>,
 * "seenResult": …}` for an unfinished puzzle. `seenResult` is required: the official result the review showed when
 * the reviewer decided, in RoundEntryResult's wire format (null, {"seconds": n}, {"piecesPlaced": n},
 * {"didNotStart": true}). The decision and the official result are written together (ConfirmOnlineResultHandler);
 * after the commit the other open pages of the round learn about it (OfficialResultsLiveUpdates::entriesChanged()).
 *
 * Answers `{status: applied | unchanged, result, momentMs, source, entry}` (the participant's review row as it is now),
 * or `{error, message}`: 409 `changed_meanwhile` when somebody entered another official result since the reviewer saw
 * it - with `current` (that result) and `entry` (the row as it is now), so the review shows it and the reviewer decides
 * again -, 422 for a refused confirmation (the reason in `error`, OnlineResultRefused) - JSON on the official results
 * API's rules: COMPETITION_RESULTS_ENTRY and ONLINE_ROUNDS_MANAGE, `Content-Type: application/json`, the stateless
 * CSRF header (OnlineOrganiserApi).
 */
final class ConfirmOnlineResultController extends AbstractController
{
    public function __construct(
        private readonly OnlineOrganiserApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly OnlineReview $onlineReview,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/kontrola/{participantId}/potvrdit',
            'en' => '/en/online-rounds/{roundId}/review/{participantId}/confirm',
            'es' => '/es/online-rounds/{roundId}/review/{participantId}/confirm',
            'ja' => '/ja/online-rounds/{roundId}/review/{participantId}/confirm',
            'fr' => '/fr/online-rounds/{roundId}/review/{participantId}/confirm',
            'de' => '/de/online-rounds/{roundId}/review/{participantId}/confirm',
        ],
        name: 'online_review_confirm',
        requirements: [
            'roundId' => FirstTryConflictsController::ID_REQUIREMENT,
            'participantId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId, string $participantId): JsonResponse
    {
        $access = $this->api->authorise($request, $roundId, review: true, write: true);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $momentMs = $body['momentMs'] ?? null;
        $pieces = $body['pieces'] ?? null;
        $source = is_string($body['source'] ?? null) ? OnlineConfirmedSource::tryFrom($body['source']) : null;
        $seenResult = self::seenResult($body);
        $round = $access['round'];
        $roundId = $round->id->toString();

        if ($source === null || $seenResult === null || !($momentMs === null || is_int($momentMs)) || !($pieces === null || is_int($pieces))) {
            return $this->refusal(OnlineResultRefused::because(OnlineResultRefused::INVALID_CONFIRMATION));
        }

        try {
            $envelope = $this->messageBus->dispatch(new ConfirmOnlineResult(
                competitionId: $round->competition->id->toString(),
                roundId: $roundId,
                participantId: strtolower($participantId),
                momentMs: $momentMs,
                source: $source,
                pieces: $pieces,
                actingPlayerId: $access['playerId'],
                seenResult: $seenResult,
            ));
        } catch (OnlineResultRefused $refused) {
            if ($refused->getStatusCode() === JsonResponse::HTTP_CONFLICT) {
                // Somebody entered another result meanwhile - what it is now, so the reviewer decides again
                return $this->refusal($refused, [
                    'current' => $refused->current?->toWire(),
                    'entry' => $this->onlineReview->row($round, $participantId),
                ]);
            }

            return $this->refusal($refused);
        } catch (OnlineRoundNotFound) {
            return OfficialResultsApi::error('round_not_online', JsonResponse::HTTP_NOT_FOUND);
        }

        $confirmed = $envelope->last(HandledStamp::class)?->getResult();
        assert($confirmed instanceof ConfirmedOnlineResult);

        // Committed by now - the results desk, the live entry and the other reviewers' pages learn about it
        $this->liveUpdates->entriesChanged($roundId, $confirmed->changedEntryRefs);

        return OfficialResultsApi::json([
            'status' => $confirmed->status->value,
            'result' => $confirmed->result->toWire(),
            'momentMs' => $confirmed->momentMs,
            'source' => $confirmed->source->value,
            'entry' => $this->onlineReview->row($round, $participantId),
        ]);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function refusal(OnlineResultRefused $refused, array $details = []): JsonResponse
    {
        return OfficialResultsApi::error($refused->reason, $refused->getStatusCode(), [
            'message' => $this->translator->trans($refused->translationKey()),
            ...$details,
        ]);
    }

    /**
     * The result the reviewer saw - required, in RoundEntryResult's wire format (JSON null = no result yet); null when
     * the field is missing or unreadable
     *
     * @param array<string, mixed> $body
     */
    private static function seenResult(array $body): null|RoundEntryResult
    {
        if (array_key_exists('seenResult', $body) === false) {
            return null;
        }

        try {
            return RoundEntryResult::fromWire($body['seenResult']);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
