<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantAlreadyLinked;
use SpeedPuzzling\Web\Message\GenerateParticipantClaimTokens;
use SpeedPuzzling\Web\Message\RegenerateParticipantClaimToken;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A person's personal claim link for the organiser (docs/features/competitions-management/participant-linking.md §7.1,
 * §7.4): the participants sheet's row actions "Copy claim link" / "Show claim QR" / "New link" and the check-in desk's
 * "Show QR". `{"participant": "<id>", "renew": false}` makes the link when the person has none yet
 * (GenerateParticipantClaimTokens for this one person), `"renew": true` replaces it (RegenerateParticipantClaimToken -
 * the old link stops working). Answers `{"participant", "url", "qr"}`: the link without a language and its QR code as
 * inline SVG (NameTagQrCode, cached per URL).
 *
 * The official results API rules (OfficialResultsApi): the event's organisers only, JSON + the `X-CSRF-Token` header,
 * `private, no-store`. Refusals: 404 `participant_not_found` (another event's or a removed participant), 409
 * `already_linked` (a claim link is for people without a profile), 409 `online_timing` (an event with online timing
 * hands out its personal links instead, §5.6), each with a translated `message`.
 */
final class ParticipantsSheetClaimLinkController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionParticipantRepository $participantRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OfficialResultsApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly NameTagQrCode $nameTagQrCode,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/participants-sheet-api/{competitionId}/claim-link',
        name: 'participants_sheet_claim_link',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        $competitionId = $this->competitionRepository->get($competitionId)->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: true);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $participantId = $body['participant'] ?? null;
        $renew = $body['renew'] ?? false;

        if (!is_string($participantId) || !is_bool($renew)) {
            return OfficialResultsApi::error('invalid_request', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            // Only a participant of this event on its list - an id of another event's participant is never reached
            $participant = $this->participantRepository->getActiveOfCompetition($competitionId, $participantId);
        } catch (CompetitionParticipantNotFound) {
            return $this->refusal('participant_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        $participantId = $participant->id->toString();

        if ($participant->player !== null) {
            return $this->refusal('already_linked', JsonResponse::HTTP_CONFLICT);
        }

        if ($this->onlineRoundRepository->ofCompetition($competitionId) !== []) {
            return $this->refusal('online_timing', JsonResponse::HTTP_CONFLICT);
        }

        try {
            if ($renew || $participant->claimToken === null) {
                $this->messageBus->dispatch($renew
                    ? new RegenerateParticipantClaimToken($competitionId, $participantId)
                    : new GenerateParticipantClaimTokens($competitionId, [$participantId]));
            }
        } catch (CompetitionParticipantNotFound) {
            return $this->refusal('participant_not_found', JsonResponse::HTTP_NOT_FOUND);
        } catch (ParticipantAlreadyLinked) {
            return $this->refusal('already_linked', JsonResponse::HTTP_CONFLICT);
        }

        // The row as it is after the commit (somebody may have linked the person meanwhile - then no link to hand out)
        $this->participantRepository->refresh($participant);
        $token = $participant->claimToken;

        if ($token === null || $participant->player !== null) {
            return $this->refusal('already_linked', JsonResponse::HTTP_CONFLICT);
        }

        $url = $this->nameTagQrCode->claimUrl($token);

        return OfficialResultsApi::json([
            'participant' => $participantId,
            'url' => $url,
            'qr' => $this->nameTagQrCode->svg($url),
        ]);
    }

    private function refusal(string $error, int $status): JsonResponse
    {
        return OfficialResultsApi::error($error, $status, [
            'message' => $this->translator->trans('participant_claim_link.sheet.error.' . $error),
        ]);
    }
}
