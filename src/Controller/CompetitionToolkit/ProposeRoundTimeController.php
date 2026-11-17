<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\ProposeRoundTime;
use SpeedPuzzling\Web\Query\GetTimeProposals;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitorHeartbeats;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalApi;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatRecord;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Stop - propose my time" of a competitor's own page (competition toolkit P1, toolkit/B-qr-competitor-page.md §5,
 * R-review-and-plan.md §2.4): `POST {"proposalId": "<the phone's UUIDv7>", "entry": "participant_round:<id>" |
 * "team:<id>", "tappedAtMs": <the phone's server-synced clock at the tap>, "offsetMs", "rttMs", "stateHash",
 * "offsetSampleAgeMs", "queuedOffline", "attempts", "sentAtMs", "expectedPlayerId"}` (another player signed in
 * meanwhile: 409 `another_account`, OfficialResultsApi::anotherAccount()) → ProposeRoundTime → `{status:
 * proposed|known|refused, reason, message, proposal}` (TimeProposalApi). The server's receipt moment is taken here,
 * before the dispatch waits for the event's lock - and the timing evidence (reliability hardening T2): what the server
 * last heard from this player's page for this entry (CompetitorHeartbeats, one redis-state read - a store that does not
 * answer means no evidence, the tap is trusted).
 *
 * Limited per player and round (`time_proposal`, 10 in 10 minutes - a stuck button never floods the judges, 429
 * `rate_limited`). A stored proposal is published to the round's judges after the commit (official_results.proposals).
 */
final class ProposeRoundTimeController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TimeProposalApi $api,
        private readonly GetTimeProposals $getTimeProposals,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $timeProposalLimiter,
        private readonly CompetitorHeartbeats $heartbeats,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/{_locale}/competitor-proposals/rounds/{roundId}',
        name: 'competitor_propose_time',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        // The receipt - before anything waits
        $receivedAtMs = RoundClock::milliseconds($this->clock->now());
        $playerId = $this->api->authorise($request);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        try {
            $round = $this->roundRepository->get($roundId);
        } catch (CompetitionRoundNotFound) {
            return $this->api->refusal('round_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        // The phone's tap of another player than the one signed in now: kept on the phone (409), never refused
        $anotherAccount = OfficialResultsApi::anotherAccount($body, $playerId);

        if ($anotherAccount !== null) {
            return $anotherAccount;
        }

        $proposalId = $body['proposalId'] ?? null;
        $entry = $body['entry'] ?? null;
        $tappedAtMs = $body['tappedAtMs'] ?? null;
        $stateHash = $body['stateHash'] ?? null;

        if (!is_string($proposalId) || !is_string($entry) || !is_int($tappedAtMs) || ($stateHash !== null && !is_string($stateHash))) {
            return $this->api->refusal('invalid_proposal', JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($this->timeProposalLimiter->create($playerId . '-' . $round->id->toString())->consume()->isAccepted() === false) {
            return $this->api->refusal('rate_limited', JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $envelope = $this->messageBus->dispatch(new ProposeRoundTime(
            competitionId: $round->competition->id->toString(),
            roundId: $round->id->toString(),
            entry: $entry,
            proposalId: $proposalId,
            actingPlayerId: $playerId,
            tappedAtMs: $tappedAtMs,
            receivedAtMs: $receivedAtMs,
            offsetMs: self::number($body['offsetMs'] ?? null),
            rttMs: self::number($body['rttMs'] ?? null),
            stateHash: $stateHash,
            offsetSampleAgeMs: self::integer($body['offsetSampleAgeMs'] ?? null),
            queuedOffline: ($body['queuedOffline'] ?? false) === true,
            attempts: self::integer($body['attempts'] ?? null),
            sentAtMs: self::integer($body['sentAtMs'] ?? null),
            heartbeat: $this->heartbeat($round->id->toString(), $entry, $playerId),
        ));

        $answer = $envelope->last(HandledStamp::class)?->getResult();
        assert($answer instanceof TimeProposalAnswer);

        // Committed by now - the judges of the round learn about it
        if ($answer->changed() && $answer->proposalId !== null) {
            $this->liveUpdates->proposalsChanged($answer->roundId, [$answer->proposalId]);
        }

        $views = $answer->proposalId !== null ? $this->getTimeProposals->byIds($round->id->toString(), [$answer->proposalId]) : [];

        return $this->api->answer($answer, $views[0] ?? null);
    }

    private function heartbeat(string $roundId, string $entry, string $playerId): null|CompetitorHeartbeatRecord
    {
        try {
            return $this->heartbeats->of($roundId, $entry, $playerId);
        } catch (\Throwable $e) {
            $this->logger->warning('Reading a competitor heartbeat failed', [
                'round_id' => $roundId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    private static function integer(mixed $value): null|int
    {
        if (is_float($value) && is_finite($value) && abs($value) < 1e15) {
            return (int) round($value);
        }

        return is_int($value) ? $value : null;
    }

    private static function number(mixed $value): null|float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
