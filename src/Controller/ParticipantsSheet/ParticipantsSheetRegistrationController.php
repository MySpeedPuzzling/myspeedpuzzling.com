<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Exceptions\RegistrationNotManaged;
use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Message\UndoParticipantCheckIn;
use SpeedPuzzling\Web\Message\UnmarkParticipantPaid;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;
use SpeedPuzzling\Web\Query\GetParticipantsSheetState;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The managed registration actions of the participants spreadsheet (docs/features/competitions-management/registration.md
 * "Organiser tools"): `{"participant": "<id>", "action": "markPaid" | "unmarkPaid" | "promote" | "promoteAndMarkPaid" |
 * "checkIn" | "undoCheckIn"}` dispatches the existing message - its rules and e-mails unchanged, under the event's lock.
 * The participant is looked up in this event first (404 `participant_not_found` for any other); an event without
 * management is 409 `registration_not_managed`; the domain's refusals are 409 with a translated message. Answers the
 * sheet's new version and the person's row, and tells the other open sheets (ParticipantsSheetLiveUpdates) after the
 * commit.
 */
final class ParticipantsSheetRegistrationController extends AbstractController
{
    private const array ACTIONS = ['markPaid', 'unmarkPaid', 'promote', 'promoteAndMarkPaid', 'checkIn', 'undoCheckIn'];

    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetCompetitionParticipantsForManagement $getParticipants,
        private readonly GetParticipantsSheetState $getParticipantsSheetState,
        private readonly GetParticipantsSheetVersion $getParticipantsSheetVersion,
        private readonly OfficialResultsApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly ParticipantsSheetLiveUpdates $liveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/participants-sheet-api/{competitionId}/registration',
        name: 'participants_sheet_registration',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        $competition = $this->competitionRepository->get($competitionId);
        $competitionId = $competition->id->toString();
        $actingPlayerId = $this->api->authorise($request, $competitionId, write: true);

        if ($actingPlayerId instanceof JsonResponse) {
            return $actingPlayerId;
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $participantId = $body['participant'] ?? null;
        $action = $body['action'] ?? null;

        if (!is_string($participantId) || !is_string($action) || !in_array($action, self::ACTIONS, true)) {
            return $this->refusal('invalid_request', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            // Only a participant of this event - an id of another event's participant is never reached
            $participant = $this->getParticipants->byId($competitionId, $participantId);
        } catch (CompetitionParticipantNotFound) {
            return $this->refusal('participant_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        if ($competition->registrationManaged === false) {
            return $this->refusal('registration_not_managed', JsonResponse::HTTP_CONFLICT);
        }

        if ($participant->isDeleted()) {
            return $this->refusal('participant_removed', JsonResponse::HTTP_CONFLICT);
        }

        $participantId = $participant->participantId;

        try {
            $this->messageBus->dispatch(match ($action) {
                'markPaid' => new MarkParticipantPaid(competitionId: $competitionId, participantId: $participantId),
                // Paid while on the waitlist: a spot and the payment in one explicit step
                'promoteAndMarkPaid' => new MarkParticipantPaid(competitionId: $competitionId, participantId: $participantId, promoteFromWaitlist: true),
                'unmarkPaid' => new UnmarkParticipantPaid(competitionId: $competitionId, participantId: $participantId),
                'promote' => new PromoteParticipantFromWaitlist(competitionId: $competitionId, participantId: $participantId),
                'checkIn' => new CheckInParticipant(competitionId: $competitionId, participantId: $participantId),
                default => new UndoParticipantCheckIn(competitionId: $competitionId, participantId: $participantId),
            });
        } catch (RegistrationNotManaged) {
            // Management switched off meanwhile - the handler checked it again under the lock
            return $this->refusal('registration_not_managed', JsonResponse::HTTP_CONFLICT);
        } catch (ParticipantIsWaitlisted) {
            return $this->refusal($action === 'checkIn' ? 'waitlisted_check_in' : 'waitlisted_paid', JsonResponse::HTTP_CONFLICT);
        } catch (CompetitionParticipantNotFound) {
            // Removed meanwhile
            return $this->refusal('participant_removed', JsonResponse::HTTP_CONFLICT);
        }

        // Read after the commit, outside the event's lock: somebody else's change may be in it already - a page merges
        // `person` and lets its live updates / version check fetch the rest, it never adopts this as its known version
        $version = $this->getParticipantsSheetVersion->ofCompetition($competitionId);

        try {
            $person = $this->getParticipantsSheetState->person($competitionId, $participantId, $actingPlayerId);
        } catch (CompetitionParticipantNotFound) {
            // Participants are only ever removed softly - the row vanishes with its event, deleted meanwhile
            return OfficialResultsApi::error('competition_not_found', JsonResponse::HTTP_NOT_FOUND, [
                'message' => $this->translator->trans('official_results.reason.competition_not_found'),
            ]);
        }

        $this->liveUpdates->changed($competitionId, $version);

        return OfficialResultsApi::json([
            'ok' => true,
            'version' => $version,
            'person' => $person,
        ]);
    }

    private function refusal(string $error, int $status): JsonResponse
    {
        return OfficialResultsApi::error($error, $status, [
            'message' => $this->translator->trans('participants_sheet.registration.error.' . $error),
        ]);
    }
}
