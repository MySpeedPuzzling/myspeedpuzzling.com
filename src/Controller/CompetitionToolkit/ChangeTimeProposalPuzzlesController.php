<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ChangeTimeProposalPuzzles;
use SpeedPuzzling\Web\Query\GetTimeProposals;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Services\AfterResponse;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalApi;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\ProposalPuzzleChoice;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Change puzzles" of a pending time proposal on the competitor's own page (competition toolkit v2 C2, design-v2
 * competitors-splits.md §2.2): `POST {"puzzles": [{"position": 2, "label": "B"}, …] | null | [], "chosenAtMs", "expectedPlayerId"}`
 * (another player signed in meanwhile: 409 `another_account`; `null` or `[]` clears the choice, a missing key is
 * unreadable) → ChangeTimeProposalPuzzles → `{status: chosen|known|refused, reason, message, proposal}`
 * (TimeProposalApi). Unreadable JSON is 400 `invalid_json`, unreadable puzzles 400 `invalid_puzzles`
 * (this request carries no tap - nothing is lost); `puzzles_changed` (a reorder or relabel since the phone's state)
 * is the phone's cue to fetch its state and ask again. `chosenAtMs` (optional, the phone's server-synced clock when the
 * choice was made): a choice older than the stored one is answered `known` and changes nothing - an outbox replaying
 * an old request late never overwrites a newer choice.
 *
 * Limited per player and proposal (`time_proposal_puzzles`, 30 in 10 minutes - its own budget, so choosing never eats
 * the tap's `time_proposal` budget; 429 `rate_limited`). A change is published to the round's judges after the commit,
 * once the answer is sent (official_results.proposals, AfterResponse).
 */
final class ChangeTimeProposalPuzzlesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TimeProposalApi $api,
        private readonly GetTimeProposals $getTimeProposals,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly AfterResponse $afterResponse,
        private readonly RateLimiterFactoryInterface $timeProposalPuzzlesLimiter,
    ) {
    }

    #[Route(
        path: '/{_locale}/competitor-proposals/{proposalId}/puzzles',
        name: 'competitor_time_proposal_puzzles',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'proposalId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $proposalId): JsonResponse
    {
        $playerId = $this->api->authorise($request);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        // Never read an unreadable body as "no puzzles" - that would clear the choice
        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        // A choice of another player than the one signed in now stays on the phone (409 `another_account`)
        $anotherAccount = OfficialResultsApi::anotherAccount($body, $playerId);

        if ($anotherAccount !== null) {
            return $anotherAccount;
        }

        try {
            if (!array_key_exists('puzzles', $body)) {
                throw new \InvalidArgumentException('The puzzles are missing.');
            }

            $puzzles = ProposalPuzzleChoice::fromWire($body['puzzles']);
        } catch (\InvalidArgumentException) {
            return $this->api->refusal(ProposalPuzzleChoice::INVALID, JsonResponse::HTTP_BAD_REQUEST);
        }

        // The event whose lock the change takes
        $location = $this->getTimeProposals->locate($proposalId);

        if ($location === null) {
            return $this->api->refusal('proposal_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        if ($this->timeProposalPuzzlesLimiter->create($playerId . '-' . strtolower($proposalId))->consume()->isAccepted() === false) {
            return $this->api->refusal('rate_limited', JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $envelope = $this->messageBus->dispatch(new ChangeTimeProposalPuzzles(
            competitionId: $location['competitionId'],
            proposalId: $proposalId,
            actingPlayerId: $playerId,
            puzzles: $puzzles,
            chosenAtMs: is_int($body['chosenAtMs'] ?? null) ? $body['chosenAtMs'] : null,
        ));

        $answer = $envelope->last(HandledStamp::class)?->getResult();
        assert($answer instanceof TimeProposalAnswer);

        // Committed by now - the judges learn about it once the phone has its answer (AfterResponse)
        if ($answer->changed() && $answer->proposalId !== null) {
            $changedId = $answer->proposalId;
            $this->afterResponse->defer(fn () => $this->liveUpdates->proposalsChanged($answer->roundId, [$changedId]));
        }

        // Only to somebody it belongs to - a refusal shows nothing of the proposal
        $views = $answer->status !== TimeProposalAnswer::REFUSED && $answer->proposalId !== null
            ? $this->getTimeProposals->byIds($location['roundId'], [$answer->proposalId])
            : [];

        return $this->api->answer($answer, $views[0] ?? null);
    }
}
