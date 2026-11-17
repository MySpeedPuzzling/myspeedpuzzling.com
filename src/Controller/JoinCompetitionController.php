<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\RegistrationNotOpen;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Query\GetParticipantClaimPolicy;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ClaimOutcome;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventJustJoined;
use SpeedPuzzling\Web\Value\ParticipantClaimPolicy;
use SpeedPuzzling\Web\Value\RegistrationStatus;
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

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class JoinCompetitionController extends AbstractController
{
    /** The confirmation form of a registration (managed events) - per event, so a token of one event registers nowhere else */
    public const string REGISTER_CSRF_PREFIX = 'competition-register-';

    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionParticipants $getCompetitionParticipants,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly GetMarketplaceEvents $getMarketplaceEvents,
        private readonly ClockInterface $clock,
        private readonly GetEventAttendance $getEventAttendance,
        private readonly IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private readonly ParticipantsSheetLiveUpdates $sheetLiveUpdates,
        private readonly GetParticipantClaimPolicy $getParticipantClaimPolicy,
        private readonly RateLimiterFactoryInterface $participantClaimLimiter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prihlasit-se-na-udalost/{competitionId}',
            'en' => '/en/join-event/{competitionId}',
            'es' => '/es/unirse-evento/{competitionId}',
            'ja' => '/ja/イベント参加/{competitionId}',
            'fr' => '/fr/rejoindre-evenement/{competitionId}',
            'de' => '/de/event-beitreten/{competitionId}',
        ],
        name: 'join_competition',
    )]
    public function __invoke(string $competitionId, Request $request): Response
    {
        // Joining needs a publicly visible event (docs/features/organizations/README.md, P17) - not a draft, not one
        // waiting for approval or rejected: this page lists the organiser's names, and its page is not public either.
        // GET and POST, both flows; JoinCompetitionHandler refuses it too
        if ($this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $competition = $this->getCompetitionEvents->byId($competitionId);
        // Every way out leads to the competition's page - for an edition its own page, never event_detail with its slug
        $competitionUrl = $this->competitionDetailUrl->of($competitionId);
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirect($competitionUrl);
        }

        if ($competition->registrationManaged) {
            return $this->managed($competition, $profile, $competitionUrl, $request);
        }

        if ($request->isMethod('POST')) {
            $participantId = $request->request->getString('participant_id');

            if ($participantId === '' && $request->request->getBoolean('self_join') === false) {
                // Picker submitted without a name — never fall through to joining under the profile name
                return $this->redirectToRoute('join_competition', ['competitionId' => $competitionId]);
            }

            if ($participantId !== '' && $this->claimLimitReached($profile)) {
                return $this->redirectToRoute('join_competition', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
            }

            // Already going = "Change" (claiming another row of the organizer's list), no new join: no marketplace
            // follow-up. Only asked where a follow-up could happen at all.
            $wasGoing = $this->mightBeMarketplaceEvent($competition)
                && $this->getMarketplaceEvents->isPlayerGoing($competition->id, $profile->playerId);

            $outcome = null;
            $joined = $this->join($competitionId, $profile->playerId, $participantId !== '' ? $participantId : null, outcome: $outcome);

            $notLinked = $this->claimNotLinked($outcome, $competitionId, $competitionUrl);

            if ($notLinked !== null) {
                return $notLinked;
            }

            if ($wasGoing) {
                if ($joined) {
                    $this->addFlash('success', $this->translator->trans('flashes.competition_join_success'));
                }

                return $this->redirect($competitionUrl);
            }

            return $this->afterJoin($joined, $competition, $profile, $competitionUrl);
        }

        $isGoing = count($this->getCompetitionParticipants->getPlayerConnections($competitionId, $profile->playerId)) > 0;
        $hasNotConnected = $this->getCompetitionParticipants->hasNotConnectedParticipants($competitionId);
        $claimPolicy = $this->getParticipantClaimPolicy->of($competitionId)['policy'];
        $matchingParticipantId = null;

        if ($isGoing === false) {
            // Opted in and their name is on the organizer's list: no need to make them pick it
            $matchingParticipantId = $profile->playerName !== null
                ? $this->getCompetitionParticipants->findNotConnectedParticipantMatchingName($competitionId, $profile->playerName, $profile->country, $profile->playerId)
                : null;

            // R11 (participant-linking.md §0a): a GET never links a name where the organiser confirms who is who - the
            // match is only pre-selected, like on a managed event. Open events keep the one-tap GET
            $linksOnGet = $matchingParticipantId === null || $claimPolicy === ParticipantClaimPolicy::Open;

            if (($matchingParticipantId !== null && $linksOnGet) || $hasNotConnected === false) {
                $outcome = null;
                $joined = $this->join($competitionId, $profile->playerId, $matchingParticipantId, outcome: $outcome);

                if ($outcome === ClaimOutcome::AskOrganiser) {
                    // The player's claims end here (3 refusals): the page with the list stays theirs - they may still
                    // join as themselves; said once, nothing changed
                    $this->addFlash('warning', $this->translator->trans('participant_linking.flash.ask_organiser'));
                    $matchingParticipantId = null;
                } else {
                    return $this->claimNotLinked($outcome, $competitionId, $competitionUrl)
                        ?? $this->afterJoin($joined, $competition, $profile, $competitionUrl);
                }
            }
        }

        if ($hasNotConnected === false) {
            // Already going and nobody left on the list to switch to
            return $this->redirect($competitionUrl);
        }

        return $this->render('join_competition.html.twig', [
            'competition' => $competition,
            'competition_url' => $competitionUrl,
            'profile' => $profile,
            'profile_country' => CountryCode::fromCode($profile->country),
            'not_connected_participants' => $this->getCompetitionParticipants->getNotConnectedParticipants($competitionId),
            'is_self_joined' => $this->getCompetitionParticipants->isPlayerSelfJoined($competitionId, $profile->playerId),
            'matching_participant_id' => $matchingParticipantId,
            'claims_need_approval' => $claimPolicy === ParticipantClaimPolicy::Approval,
        ]);
    }

    /**
     * An event that manages registration (docs/features/competitions-management/registration.md): a GET never changes
     * anything - not even for a name found on the organiser's list - it shows the page with the fee and what happens
     * (reserved or the waitlist). A new registration is the confirmation form's POST with its CSRF token; picking a
     * name from the organiser's list is the picker's POST and works whatever the registration window says.
     */
    private function managed(CompetitionEvent $competition, PlayerProfile $profile, string $competitionUrl, Request $request): Response
    {
        $competitionId = $competition->id;

        if ($request->isMethod('POST')) {
            $participantId = $request->request->getString('participant_id');
            $selfJoin = $request->request->getBoolean('self_join');

            if ($participantId === '' && $selfJoin === false) {
                // A registration is only ever the confirmation form - never a bare POST, never a GET
                return $this->redirectToRoute('join_competition', ['competitionId' => $competitionId]);
            }

            // Both forms of the page carry the event's token - an expired one is said, never silently ignored
            if ($this->isCsrfTokenValid(self::REGISTER_CSRF_PREFIX . $competitionId, $request->request->getString('_token')) === false) {
                $this->addFlash('warning', $this->translator->trans('competition_registration.flash.form_expired'));

                return $this->redirectToRoute('join_competition', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
            }

            if ($participantId !== '' && $request->request->getBoolean('release_paid') === false) {
                // Picking a name lets go of the player's own registration - a paid one only on their explicit yes
                $current = $this->getEventAttendance->forEvent($competition, $profile->playerId, true)->registration;

                if ($current !== null && $current->playerSelfJoined && $current->isPaid()) {
                    $this->addFlash('warning', $this->translator->trans('competition_registration.flash.release_paid_not_confirmed'));

                    return $this->redirectToRoute('join_competition', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
                }
            }

            if ($participantId !== '' && $this->claimLimitReached($profile)) {
                return $this->redirectToRoute('join_competition', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
            }

            $wasGoing = $this->mightBeMarketplaceEvent($competition)
                && $this->getMarketplaceEvents->isPlayerGoing($competition->id, $profile->playerId);

            $outcome = null;
            $joined = $this->join(
                $competitionId,
                $profile->playerId,
                $participantId !== '' ? $participantId : null,
                releasesOwnRegistration: $request->request->getBoolean('release_paid'),
                outcome: $outcome,
            );

            if ($joined === false) {
                return $this->redirect($competitionUrl);
            }

            $notLinked = $this->claimNotLinked($outcome, $competitionId, $competitionUrl);

            if ($notLinked !== null) {
                return $notLinked;
            }

            if ($participantId !== '') {
                // Picked from the organiser's list - the organiser holds that spot, no registration of its own
                if ($wasGoing) {
                    $this->addFlash('success', $this->translator->trans('flashes.competition_join_success'));

                    return $this->redirect($competitionUrl);
                }

                return $this->afterJoin(true, $competition, $profile, $competitionUrl);
            }

            $registration = $this->getEventAttendance->forEvent($competition, $profile->playerId, true)->registration;

            if ($registration !== null && $registration->playerStatus === RegistrationStatus::Waitlisted) {
                // Not going - no marketplace step, the waitlist is what they got
                $this->addFlash('warning', $this->translator->trans('competition_registration.flash.waitlisted', [
                    '%position%' => $registration->playerWaitlistPosition ?? 1,
                ]));

                return $this->redirect($competitionUrl);
            }

            if ($wasGoing) {
                $this->addFlash('success', $this->translator->trans('competition_registration.flash.registered'));

                return $this->redirect($competitionUrl);
            }

            return $this->afterJoin(true, $competition, $profile, $competitionUrl, 'competition_registration.flash.registered');
        }

        $isConnected = count($this->getCompetitionParticipants->getPlayerConnections($competitionId, $profile->playerId)) > 0;
        $notConnected = $this->getCompetitionParticipants->getNotConnectedParticipants($competitionId);

        if ($isConnected && $notConnected === []) {
            // Registered (or on the list) already and nobody left on the list to switch to
            return $this->redirect($competitionUrl);
        }

        return $this->render('join_competition.html.twig', [
            'competition' => $competition,
            'competition_url' => $competitionUrl,
            'profile' => $profile,
            'profile_country' => CountryCode::fromCode($profile->country),
            'not_connected_participants' => $notConnected,
            'is_self_joined' => $this->getCompetitionParticipants->isPlayerSelfJoined($competitionId, $profile->playerId),
            // Publicly visible - checked on the way in
            'registration' => $this->getEventAttendance->forEvent($competition, $profile->playerId, true)->registration,
            // No auto-connect on a GET - the name found on the list is only pre-selected
            'matching_participant_id' => $profile->playerName !== null && $isConnected === false
                ? $this->getCompetitionParticipants->findNotConnectedParticipantMatchingName($competitionId, $profile->playerName, $profile->country, $profile->playerId)
                : null,
            'register_csrf_id' => self::REGISTER_CSRF_PREFIX . $competitionId,
            'claims_need_approval' => $this->getParticipantClaimPolicy->of($competitionId)['policy'] === ParticipantClaimPolicy::Approval,
        ]);
    }

    /**
     * @param-out null|ClaimOutcome $outcome a pick's claim outcome (participant-linking.md §5.1), null for a self-join
     * @return bool whether the player joined
     */
    private function join(
        string $competitionId,
        string $playerId,
        null|string $participantId,
        bool $releasesOwnRegistration = false,
        null|ClaimOutcome &$outcome = null,
    ): bool {
        try {
            $envelope = $this->messageBus->dispatch(new JoinCompetition(
                competitionId: $competitionId,
                playerId: $playerId,
                participantId: $participantId,
                releasesOwnRegistration: $releasesOwnRegistration,
            ));

            $result = $envelope->last(HandledStamp::class)?->getResult();
            $outcome = $result instanceof ClaimOutcome ? $result : null;

            // The organiser's open participants sheets learn about the new or changed link (participant-linking.md §5.8)
            $this->sheetLiveUpdates->changedAfterResponse($competitionId);

            return true;
        } catch (HandlerFailedException $e) {
            $notOpen = $e->getPrevious();

            if ($notOpen instanceof RegistrationNotOpen) {
                $this->addFlash('danger', $this->translator->trans('competition_registration.flash.not_open.' . $notOpen->availability->value));

                return false;
            }

            throw $e;
        }
    }

    /**
     * A pick that did not link the player (participant-linking.md §5.1, §6.2): the page says what happened instead -
     * null when it linked them (or was no pick), the usual "You're going" flow goes on.
     */
    private function claimNotLinked(null|ClaimOutcome $outcome, string $competitionId, string $competitionUrl): null|Response
    {
        return match ($outcome) {
            null, ClaimOutcome::Linked, ClaimOutcome::AlreadyYours => null,
            ClaimOutcome::Requested => $this->flashAndRedirect('success', 'participant_linking.flash.requested', $competitionUrl),
            ClaimOutcome::DisputeSent => $this->flashAndRedirect('info', 'participant_linking.flash.dispute_sent', $competitionUrl),
            ClaimOutcome::AskOrganiser => $this->flashAndRedirect('warning', 'participant_linking.flash.ask_organiser', $competitionUrl),
            ClaimOutcome::NotAvailable => $this->flashAndRedirect('warning', 'participant_linking.flash.not_available', $this->generateUrl('join_competition', ['competitionId' => $competitionId])),
            ClaimOutcome::ReleaseNotConfirmed => $this->flashAndRedirect('warning', 'competition_registration.flash.release_paid_not_confirmed', $this->generateUrl('join_competition', ['competitionId' => $competitionId])),
        };
    }

    private function flashAndRedirect(string $type, string $message, string $url): Response
    {
        $this->addFlash($type, $this->translator->trans($message));

        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }

    /**
     * Limiter `participant_claim` (participant-linking.md §10.2): 10 picks per 10 minutes per player - a pick is a request
     * to an organiser or a link, never a loop.
     */
    private function claimLimitReached(PlayerProfile $profile): bool
    {
        if ($this->participantClaimLimiter->create($profile->playerId)->consume()->isAccepted()) {
            return false;
        }

        $this->addFlash('warning', $this->translator->trans('participant_linking.flash.too_many_claims'));

        return true;
    }

    /**
     * Where "I'm going" leads (docs/features/marketplace/11-events.md). Not a marketplace event (online, over, not
     * public, undated) - the event page as always. A marketplace event: members with a published listing go on to
     * "What will you bring?" (F1), everybody else back to the event page with the marketplace card highlighted (F2).
     * One query after a successful new join to a dated in-person event that is not over (GetMarketplaceEvents decides
     * the rest), none otherwise - plus, for a POST, the attendance check before the join.
     */
    private function afterJoin(
        bool $joined,
        CompetitionEvent $competition,
        PlayerProfile $profile,
        string $competitionUrl,
        string $successFlash = 'flashes.competition_join_success',
    ): Response {
        if ($joined === false) {
            return $this->redirect($competitionUrl);
        }

        $followUp = $this->mightBeMarketplaceEvent($competition)
            ? $this->getMarketplaceEvents->joinFollowUp($competition->id, $profile->playerId, $profile->activeMembership)
            : ['qualifies' => false, 'hasPublishedListings' => false];

        if ($followUp['qualifies'] && $profile->activeMembership && $followUp['hasPublishedListings']) {
            // The picker opens with its own "You're going to …!" confirmation - no flash on top of it
            return $this->redirectToRoute('event_offers_picker', [
                'competitionId' => $competition->id,
                'joined' => 1,
            ]);
        }

        $this->addFlash('success', $this->translator->trans($successFlash));

        // Highlights the marketplace card and - every event with a partner finder is a marketplace event - the partner
        // card (docs/features/partner-finder.md D9; the picker above keeps priority)
        if ($followUp['qualifies']) {
            $this->addFlash(EventJustJoined::FLASH, $competition->id);
        }

        return $this->redirect($competitionUrl);
    }

    /**
     * What the competition row already tells without a query: online, undated and past events are never marketplace
     * events. Only a cheap pre-check - GetMarketplaceEvents::SQL_QUALIFIES stays the rule (visibility included).
     */
    private function mightBeMarketplaceEvent(CompetitionEvent $competition): bool
    {
        if ($competition->isOnline || $competition->dateFrom === null) {
            return false;
        }

        $lastDay = $competition->dateTo ?? $competition->dateFrom;

        return $lastDay->format('Y-m-d') >= $this->clock->now()->format('Y-m-d');
    }
}
