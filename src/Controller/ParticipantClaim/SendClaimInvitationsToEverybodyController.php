<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantClaim;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\ClaimInvitationsNotAvailable;
use SpeedPuzzling\Web\Message\SendParticipantClaimInvitations;
use SpeedPuzzling\Web\Results\ClaimInvitationsSent;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ClaimInvitationSkip;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The toolkit hub's "Send invitations to everybody not linked with an e-mail (N)" (docs/features/competitions-management/
 * participant-linking.md §7.5, R8): the card's confirmation POSTs here → SendParticipantClaimInvitations for the whole
 * list (the handler keeps who may be invited) → 303 back to the hub with what went out and what was skipped.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SendClaimInvitationsToEverybodyController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'claim-invitations-';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly ParticipantsSheetLiveUpdates $sheetLiveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/send-claim-invitations/{competitionId}',
        name: 'send_claim_invitations_to_everybody',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $competitionId = strtolower($competitionId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . $competitionId, $request->request->getString('_token'))) {
            $this->addFlash('warning', $this->translator->trans('participant_claim_invitation.hub.form_expired'));

            return $this->redirectToRoute('competition_toolkit', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
        }

        try {
            $result = $this->messageBus->dispatch(new SendParticipantClaimInvitations($competitionId, null, $profile->playerId))
                ->last(HandledStamp::class)?->getResult();
            assert($result instanceof ClaimInvitationsSent);
        } catch (ClaimInvitationsNotAvailable $exception) {
            $this->addFlash('warning', $this->translator->trans('participant_claim_invitation.error.' . $exception->reason));

            return $this->redirectToRoute('competition_toolkit', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
        }

        $skipped = [];

        foreach (ClaimInvitationSkip::cases() as $reason) {
            // Linked people and removed rows are not "everybody not linked" - not worth saying here
            if ($reason !== ClaimInvitationSkip::Linked && $reason !== ClaimInvitationSkip::NotOnList && $result->skippedFor($reason) > 0) {
                $skipped[] = $this->translator->trans('participant_claim_invitation.sheet.skipped_count.' . $reason->value, ['%count%' => $result->skippedFor($reason)]);
            }
        }

        $message = $this->translator->trans('participant_claim_invitation.sheet.sent', ['%count%' => $result->sent]);

        if ($skipped !== []) {
            $message .= ' ' . $this->translator->trans('participant_claim_invitation.sheet.skipped', ['%list%' => implode(' · ', $skipped)]);
        }

        $this->addFlash($result->sent > 0 ? 'success' : 'warning', $message);

        if ($result->sent > 0) {
            $this->sheetLiveUpdates->changedAfterResponse($competitionId);
        }

        return $this->redirectToRoute('competition_toolkit', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
    }
}
