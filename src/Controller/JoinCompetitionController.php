<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetMarketplaceEvents;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventJustJoined;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class JoinCompetitionController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionParticipants $getCompetitionParticipants,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly GetMarketplaceEvents $getMarketplaceEvents,
        private readonly ClockInterface $clock,
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
        $competition = $this->getCompetitionEvents->byId($competitionId);
        // Every way out leads to the competition's page - for an edition its own page, never event_detail with its slug
        $competitionUrl = $this->competitionDetailUrl->of($competitionId);
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirect($competitionUrl);
        }

        if ($request->isMethod('POST')) {
            $participantId = $request->request->getString('participant_id');

            if ($participantId === '' && $request->request->getBoolean('self_join') === false) {
                // Picker submitted without a name — never fall through to joining under the profile name
                return $this->redirectToRoute('join_competition', ['competitionId' => $competitionId]);
            }

            // Already going = "Change" (claiming another row of the organizer's list), no new join: no marketplace
            // follow-up. Only asked where a follow-up could happen at all.
            $wasGoing = $this->mightBeMarketplaceEvent($competition)
                && $this->getMarketplaceEvents->isPlayerGoing($competition->id, $profile->playerId);

            $joined = $this->join($competitionId, $profile->playerId, $participantId !== '' ? $participantId : null);

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

        if ($isGoing === false) {
            // Opted in and their name is on the organizer's list: no need to make them pick it
            $matchingParticipantId = $profile->playerName !== null
                ? $this->getCompetitionParticipants->findNotConnectedParticipantMatchingName($competitionId, $profile->playerName, $profile->country)
                : null;

            if ($matchingParticipantId !== null || $hasNotConnected === false) {
                $joined = $this->join($competitionId, $profile->playerId, $matchingParticipantId);

                return $this->afterJoin($joined, $competition, $profile, $competitionUrl);
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
        ]);
    }

    /**
     * @return bool whether the player joined
     */
    private function join(string $competitionId, string $playerId, null|string $participantId): bool
    {
        try {
            $this->messageBus->dispatch(new JoinCompetition(
                competitionId: $competitionId,
                playerId: $playerId,
                participantId: $participantId,
            ));

            return true;
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof CompetitionParticipantAlreadyConnectedToDifferentPlayer) {
                $this->addFlash('danger', $this->translator->trans('flashes.competition_duplicate_connection'));

                return false;
            }

            throw $e;
        }
    }

    /**
     * Where "I'm going" leads (docs/features/marketplace/11-events.md). Not a marketplace event (online, over, not
     * public, undated) - the event page as always. A marketplace event: members with a published listing go on to
     * "What will you bring?" (F1), everybody else back to the event page with the marketplace card highlighted (F2).
     * One query after a successful new join to a dated in-person event that is not over (GetMarketplaceEvents decides
     * the rest), none otherwise - plus, for a POST, the attendance check before the join.
     */
    private function afterJoin(bool $joined, CompetitionEvent $competition, PlayerProfile $profile, string $competitionUrl): Response
    {
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

        $this->addFlash('success', $this->translator->trans('flashes.competition_join_success'));

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
