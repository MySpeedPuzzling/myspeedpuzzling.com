<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantLinking;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Message\ClaimParticipant;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\CompetitorPageUrl;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ClaimOutcome;
use SpeedPuzzling\Web\Value\CompetitionTag;
use SpeedPuzzling\Web\Value\CompetitionTagKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "This is me" on a competitor page (docs/features/competitions-management/participant-linking.md §6.4, R9): the
 * signed-in viewer claims a person of the page nobody is linked to - `ClaimParticipant`, whose handler decides
 * (ParticipantClaims: linked at once, a request the organiser confirms, a dispute, "ask the organiser"). CSRF per event,
 * limiter `participant_claim`, 303 with a flash back to the competitor page it came from (else the event page).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ClaimParticipantController extends AbstractController
{
    public const string CSRF_PREFIX = 'participant-claim-';

    public function __construct(
        private readonly IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly CompetitorPageUrl $competitorPageUrl,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly ParticipantsSheetLiveUpdates $sheetLiveUpdates,
        private readonly RateLimiterFactoryInterface $participantClaimLimiter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/to-jsem-ja/{competitionId}/{participantId}',
            'en' => '/en/claim-participant/{competitionId}/{participantId}',
            'es' => '/es/claim-participant/{competitionId}/{participantId}',
            'ja' => '/ja/claim-participant/{competitionId}/{participantId}',
            'fr' => '/fr/claim-participant/{competitionId}/{participantId}',
            'de' => '/de/claim-participant/{competitionId}/{participantId}',
        ],
        name: 'claim_participant',
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, string $participantId, Request $request): Response
    {
        // A draft's (or an unknown event's) list is nobody's to claim from - the handler refuses it too
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($participantId) === false || $this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $competitionId = strtolower($competitionId);
        $participantId = strtolower($participantId);
        $backUrl = $this->backUrl($competitionId, $request);
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirect($backUrl, Response::HTTP_SEE_OTHER);
        }

        if ($this->isCsrfTokenValid(self::CSRF_PREFIX . $competitionId, $request->request->getString('_token')) === false) {
            return $this->flashAndRedirect('warning', 'participant_linking.flash.form_expired', $backUrl);
        }

        if ($this->participantClaimLimiter->create($profile->playerId)->consume()->isAccepted() === false) {
            return $this->flashAndRedirect('warning', 'participant_linking.flash.too_many_claims', $backUrl);
        }

        try {
            $envelope = $this->messageBus->dispatch(new ClaimParticipant(
                competitionId: $competitionId,
                playerId: $profile->playerId,
                participantId: $participantId,
                releasesOwnRegistration: $request->request->getBoolean('release_paid'),
            ));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof CompetitionParticipantNotFound) {
                return $this->flashAndRedirect('warning', 'participant_linking.flash.not_available', $backUrl);
            }

            throw $e;
        }

        $outcome = $envelope->last(HandledStamp::class)?->getResult();

        // The organiser's open participants sheets learn about the new link or request (participant-linking.md §5.8)
        $this->sheetLiveUpdates->changedAfterResponse($competitionId);

        return match ($outcome) {
            ClaimOutcome::Linked => $this->flashAndRedirect('success', 'participant_linking.find.flash_linked', $backUrl),
            ClaimOutcome::AlreadyYours => $this->flashAndRedirect('info', 'participant_linking.find.flash_already_yours', $backUrl),
            ClaimOutcome::Requested => $this->flashAndRedirect('success', 'participant_linking.flash.requested', $backUrl),
            ClaimOutcome::DisputeSent => $this->flashAndRedirect('info', 'participant_linking.flash.dispute_sent', $backUrl),
            ClaimOutcome::AskOrganiser => $this->flashAndRedirect('warning', 'participant_linking.flash.ask_organiser', $backUrl),
            // The player's own paid registration needs their explicit yes - the join page asks it, with this name picked
            ClaimOutcome::ReleaseNotConfirmed => $this->flashAndRedirect(
                'warning',
                'competition_registration.flash.release_paid_not_confirmed',
                $this->generateUrl('join_competition', ['competitionId' => $competitionId, 'participant' => $participantId]),
            ),
            default => $this->flashAndRedirect('warning', 'participant_linking.flash.not_available', $backUrl),
        };
    }

    /**
     * The competitor page the claim came from (a person or line-up tag of this event - `kind` + `tag` of the form), else
     * the event page's Taking part.
     */
    private function backUrl(string $competitionId, Request $request): string
    {
        $tagId = $request->request->getString('tag');
        $tag = Uuid::isValid($tagId) ? match (CompetitionTagKind::tryFrom($request->request->getString('kind'))) {
            CompetitionTagKind::Participant => CompetitionTag::participant(strtolower($tagId)),
            CompetitionTagKind::Lineup => CompetitionTag::lineup(strtolower($tagId)),
            default => null,
        } : null;

        if ($tag !== null) {
            return $this->competitorPageUrl->of($competitionId, $tag, $request->getLocale());
        }

        // Also the 404 of an unknown competition. For an edition its own page
        return $this->competitionDetailUrl->of($competitionId) . '#taking-part';
    }

    private function flashAndRedirect(string $type, string $message, string $url): Response
    {
        $this->addFlash($type, $this->translator->trans($message));

        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }
}
