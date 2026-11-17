<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\WithdrawTimeProposal;
use SpeedPuzzling\Web\Query\GetTimeProposals;
use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\TimeProposalApi;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Undo" of a pending time proposal on the competitor's own page (competition toolkit P1): `POST {}` (or
 * `{"expectedPlayerId": …}` - another player signed in meanwhile: 409 `another_account`) →
 * WithdrawTimeProposal → `{status: withdrawn|known|refused, reason, message, proposal}` (TimeProposalApi). A proposal
 * a judge decided meanwhile answers `known` with its state. Published to the round's judges after the commit.
 */
final class WithdrawTimeProposalController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TimeProposalApi $api,
        private readonly GetTimeProposals $getTimeProposals,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
    ) {
    }

    #[Route(
        path: '/{_locale}/competitor-proposals/{proposalId}/withdraw',
        name: 'competitor_withdraw_time_proposal',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'proposalId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $proposalId): JsonResponse
    {
        $playerId = $this->api->authorise($request);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        // An Undo of another player than the one signed in now stays on the phone (409 `another_account`)
        $anotherAccount = OfficialResultsApi::anotherAccount(OfficialResultsApi::bodyOrEmpty($request), $playerId);

        if ($anotherAccount !== null) {
            return $anotherAccount;
        }

        // The event whose lock the withdrawal takes
        $location = $this->getTimeProposals->locate($proposalId);

        if ($location === null) {
            return $this->api->refusal('proposal_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        $envelope = $this->messageBus->dispatch(new WithdrawTimeProposal(
            competitionId: $location['competitionId'],
            proposalId: $proposalId,
            actingPlayerId: $playerId,
        ));

        $answer = $envelope->last(HandledStamp::class)?->getResult();
        assert($answer instanceof TimeProposalAnswer);

        if ($answer->changed() && $answer->proposalId !== null) {
            $this->liveUpdates->proposalsChanged($answer->roundId, [$answer->proposalId]);
        }

        // Only to somebody it belongs to - a refusal shows nothing of the proposal
        $views = $answer->status !== TimeProposalAnswer::REFUSED && $answer->proposalId !== null
            ? $this->getTimeProposals->byIds($location['roundId'], [$answer->proposalId])
            : [];

        return $this->api->answer($answer, $views[0] ?? null);
    }
}
