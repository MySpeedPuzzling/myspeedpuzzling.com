<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantClaim;

use SpeedPuzzling\Web\Message\ClaimParticipant;
use SpeedPuzzling\Web\Message\ClaimParticipantByToken;
use SpeedPuzzling\Web\Query\GetParticipantClaimPage;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\ParticipantLinking\ClaimPageLimit;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ClaimLinkOutcome;
use SpeedPuzzling\Web\Value\ClaimOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The claim page's buttons (docs/features/competitions-management/participant-linking.md §6.5), always 303:
 * - "Yes, link my profile" / "Switch" (`switch=1`) → ClaimParticipantByToken: linked → the event page (`#taking-part`)
 *   with a flash - on an event with online timing it has "Open my competition page" from now on (§5.6);
 * - "It's me - tell the organiser" (`action=dispute`) on a name linked to another profile → ClaimParticipant (the
 *   dispute request, ParticipantClaims - limiter `participant_claim` per player like every pick).
 * Anything the page must say instead (linked to another meanwhile, the switch, not valid any more) → back to the page,
 * which shows it. A switch or a dispute that lets go of the player's own paid registration needs their yes
 * (`release_paid` - the page asks for it, registration.md). The page's limits (ClaimPageLimit); the stateless CSRF token
 * of the forms.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ParticipantClaimConfirmController extends AbstractController
{
    public function __construct(
        private readonly GetParticipantClaimPage $getParticipantClaimPage,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly MessageBusInterface $messageBus,
        private readonly ParticipantsSheetLiveUpdates $sheetLiveUpdates,
        private readonly TranslatorInterface $translator,
        private readonly ClaimPageLimit $claimPageLimit,
        private readonly RateLimiterFactoryInterface $participantClaimLimiter,
    ) {
    }

    #[Route(
        path: '/{_locale}/claim/{token}',
        name: 'participant_claim_confirm',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $token): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $pageUrl = $this->generateUrl('participant_claim', ['token' => $token]);

        if ($profile === null) {
            return $this->withFlash('warning', 'participant_claim_link.flash.no_profile', $pageUrl);
        }

        if ($this->claimPageLimit->accepts($request, $profile->playerId) === false) {
            return $this->withFlash('warning', 'participant_claim_link.flash.too_many', $pageUrl);
        }

        if ($this->isCsrfTokenValid(ParticipantClaimController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            return $this->withFlash('warning', 'participant_linking.flash.form_expired', $pageUrl);
        }

        $page = $this->getParticipantClaimPage->byToken($token, $profile->playerId);

        // The page says why (not valid any more - also to the event's team: nothing links on a draft)
        if ($page === null || $page->removed || $page->publiclyVisible === false) {
            return $this->redirect($pageUrl, Response::HTTP_SEE_OTHER);
        }

        $eventUrl = $this->competitionDetailUrl->of($page->competitionId);

        if ($request->request->getString('action') === 'dispute') {
            // Only a name linked to somebody else is disputed - unlinked meanwhile, the link itself works: the page offers it
            if ($page->linkedPlayerId === null || $page->isLinkedTo($profile->playerId)) {
                return $this->redirect($pageUrl, Response::HTTP_SEE_OTHER);
            }

            return $this->dispute($page->competitionId, $page->participantId, $profile->playerId, $request->request->getBoolean('release_paid'), $eventUrl, $pageUrl);
        }

        $outcome = $this->messageBus->dispatch(new ClaimParticipantByToken(
            competitionId: $page->competitionId,
            token: $token,
            playerId: $profile->playerId,
            switch: $request->request->getBoolean('switch'),
            releasesOwnRegistration: $request->request->getBoolean('release_paid'),
        ))->last(HandledStamp::class)?->getResult();

        return match ($outcome) {
            ClaimLinkOutcome::Linked => $this->linked($page->competitionId, $eventUrl),
            // Their own registration became paid after the page was shown - the page says so now, the switch asks again
            ClaimLinkOutcome::ReleaseNotConfirmed => $this->withFlash('warning', 'competition_registration.flash.release_paid_not_confirmed', $pageUrl),
            ClaimLinkOutcome::AlreadyYours => $this->withFlash('info', 'participant_claim_link.flash.already_yours', $eventUrl . '#taking-part'),
            default => $this->redirect($pageUrl, Response::HTTP_SEE_OTHER),
        };
    }

    private function linked(string $competitionId, string $eventUrl): Response
    {
        // The organiser's open participants sheets learn about the link (participant-linking.md §5.8)
        $this->sheetLiveUpdates->changedAfterResponse($competitionId);

        return $this->withFlash('success', 'participant_claim_link.flash.linked', $eventUrl . '#taking-part');
    }

    private function dispute(string $competitionId, string $participantId, string $playerId, bool $releasesOwnRegistration, string $eventUrl, string $pageUrl): Response
    {
        if ($this->participantClaimLimiter->create($playerId)->consume()->isAccepted() === false) {
            return $this->withFlash('warning', 'participant_linking.flash.too_many_claims', $pageUrl);
        }

        $outcome = $this->messageBus->dispatch(new ClaimParticipant(
            competitionId: $competitionId,
            playerId: $playerId,
            participantId: $participantId,
            releasesOwnRegistration: $releasesOwnRegistration,
        ))->last(HandledStamp::class)?->getResult();

        if ($outcome === ClaimOutcome::DisputeSent || $outcome === ClaimOutcome::Linked || $outcome === ClaimOutcome::Requested) {
            $this->sheetLiveUpdates->changedAfterResponse($competitionId);
        }

        return match ($outcome) {
            ClaimOutcome::DisputeSent => $this->withFlash('info', 'participant_claim_link.flash.dispute_sent', $eventUrl),
            ClaimOutcome::AskOrganiser => $this->withFlash('warning', 'participant_linking.flash.ask_organiser', $eventUrl),
            ClaimOutcome::ReleaseNotConfirmed => $this->withFlash('warning', 'competition_registration.flash.release_paid_not_confirmed', $pageUrl),
            ClaimOutcome::AlreadyYours => $this->withFlash('info', 'participant_claim_link.flash.already_yours', $eventUrl . '#taking-part'),
            // Unlinked meanwhile: the link itself works now - the page offers it
            default => $this->redirect($pageUrl, Response::HTTP_SEE_OTHER),
        };
    }

    private function withFlash(string $type, string $message, string $url): Response
    {
        $this->addFlash($type, $this->translator->trans($message));

        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }
}
