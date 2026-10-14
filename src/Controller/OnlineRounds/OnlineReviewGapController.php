<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineMomentVerdictRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\MarkOnlineMomentGap;
use SpeedPuzzling\Web\Message\UnmarkOnlineMomentGap;
use SpeedPuzzling\Web\Results\OnlineMomentGapChange;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reviewer's verdict on one finish moment of a participant (docs/features/online-competitions/README.md §5.9, the
 * G key - stream RG): `{"eventId": <the raise's or the tap's event id>, "gap": true}` marks its photo as showing a gap
 * (MarkOnlineMomentGap), `"gap": false` takes the mark back - "Not a gap", or the reviewer confirmed that very moment
 * (UnmarkOnlineMomentGap). The same again changes nothing, so the page may send it again after a lost answer.
 *
 * Answers `{status: marked | unmarked | unchanged, eventId, gap, gaps}` (`gaps` = the participant's moments marked as
 * it is now, as the review's detail lists them), or `{error, message}`: 422 `moment_not_found` (no finish moment of
 * this participant the review shows - never telling a hidden raise of a `shadow` round apart), `invalid_verdict` (the
 * body). JSON on the review's rules (OnlineOrganiserApi: COMPETITION_RESULTS_ENTRY and ONLINE_ROUNDS_MANAGE,
 * `Content-Type: application/json`, the stateless CSRF header) - as the confirmation. Nothing else changes: not the
 * suggestion, a confirmation or an official time.
 */
final class OnlineReviewGapController extends AbstractController
{
    public function __construct(
        private readonly OnlineOrganiserApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly OnlineReview $onlineReview,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/kontrola/{participantId}/mezera',
            'en' => '/en/online-rounds/{roundId}/review/{participantId}/gap',
            'es' => '/es/online-rounds/{roundId}/review/{participantId}/gap',
            'ja' => '/ja/online-rounds/{roundId}/review/{participantId}/gap',
            'fr' => '/fr/online-rounds/{roundId}/review/{participantId}/gap',
            'de' => '/de/online-rounds/{roundId}/review/{participantId}/gap',
        ],
        name: 'online_review_gap',
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

        $eventId = $body['eventId'] ?? null;
        $gap = $body['gap'] ?? null;

        if (!is_string($eventId) || !is_bool($gap)) {
            return OfficialResultsApi::error('invalid_verdict', JsonResponse::HTTP_UNPROCESSABLE_ENTITY, [
                'message' => $this->translator->trans('online_rounds.review.reason.invalid_verdict'),
            ]);
        }

        $round = $access['round'];
        $arguments = [
            $round->competition->id->toString(),
            $round->id->toString(),
            strtolower($participantId),
            strtolower($eventId),
            $access['playerId'],
        ];

        try {
            $envelope = $this->messageBus->dispatch($gap ? new MarkOnlineMomentGap(...$arguments) : new UnmarkOnlineMomentGap(...$arguments));
        } catch (OnlineMomentVerdictRefused $refused) {
            return OfficialResultsApi::error($refused->reason, $refused->getStatusCode(), [
                'message' => $this->translator->trans($refused->translationKey()),
            ]);
        } catch (OnlineRoundNotFound) {
            return OfficialResultsApi::error('round_not_online', JsonResponse::HTTP_NOT_FOUND);
        }

        $change = $envelope->last(HandledStamp::class)?->getResult();
        assert($change instanceof OnlineMomentGapChange);

        return OfficialResultsApi::json([
            'status' => $change->changed ? ($gap ? 'marked' : 'unmarked') : 'unchanged',
            'eventId' => strtolower($eventId),
            'gap' => $gap,
            'gaps' => $this->onlineReview->gaps($change->onlineEntryId, $access['onlineRound']->detectionMode),
        ]);
    }
}
