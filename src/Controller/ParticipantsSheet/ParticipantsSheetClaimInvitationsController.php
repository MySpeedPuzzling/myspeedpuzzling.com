<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\ClaimInvitationsNotAvailable;
use SpeedPuzzling\Web\Message\SendParticipantClaimInvitations;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\ClaimInvitationsSent;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The claim invitation e-mails from the participants sheet (docs/features/competitions-management/participant-linking.md
 * §7.1, R8): the row's "Send invitation" and the bulk bar's "Send invitations to N". `{"participants": ["<id>", …]}`
 * (at most MAX_PEOPLE) → SendParticipantClaimInvitations; answers `{"sent": N, "skipped": {"<reason>": n}}`
 * (ClaimInvitationSkip values). The open sheets hear of it after the commit (`invitedAt` in the state).
 *
 * The official results API rules (OfficialResultsApi): the event's organisers only, JSON + the `X-CSRF-Token` header,
 * `private, no-store`. Refusals: 409 `not_public` (a draft - its claim link opens nothing), 409 `online_timing` (its
 * personal links do it, §5.6), each with a translated `message`.
 */
final class ParticipantsSheetClaimInvitationsController extends AbstractController
{
    public const int MAX_PEOPLE = 5000;

    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly OfficialResultsApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly ParticipantsSheetLiveUpdates $sheetLiveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/participants-sheet-api/{competitionId}/claim-invitations',
        name: 'participants_sheet_claim_invitations',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        $competitionId = $this->competitionRepository->get($competitionId)->id->toString();
        $playerId = $this->api->authorise($request, $competitionId, write: true);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $participantIds = $body['participants'] ?? null;

        if (
            !is_array($participantIds)
            || !array_is_list($participantIds)
            || $participantIds === []
            || count($participantIds) > self::MAX_PEOPLE
            || array_any($participantIds, static fn (mixed $id): bool => !is_string($id))
        ) {
            return OfficialResultsApi::error('invalid_request', JsonResponse::HTTP_BAD_REQUEST);
        }

        $participantIds = array_values(array_filter($participantIds, is_string(...)));

        try {
            $envelope = $this->messageBus->dispatch(new SendParticipantClaimInvitations($competitionId, $participantIds, $playerId));
        } catch (ClaimInvitationsNotAvailable $exception) {
            return OfficialResultsApi::error($exception->reason, JsonResponse::HTTP_CONFLICT, [
                'message' => $this->translator->trans('participant_claim_invitation.error.' . $exception->reason),
            ]);
        }

        $result = $envelope->last(HandledStamp::class)?->getResult();
        assert($result instanceof ClaimInvitationsSent);

        if ($result->sent > 0) {
            $this->sheetLiveUpdates->changedAfterResponse($competitionId);
        }

        return OfficialResultsApi::json([
            'sent' => $result->sent,
            'skipped' => (object) $result->skipped,
        ]);
    }
}
