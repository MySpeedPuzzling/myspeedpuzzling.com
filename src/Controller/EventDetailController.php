<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Query\GetEventOffers;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Services\EventDetail\EventPagePuzzles;
use SpeedPuzzling\Web\Services\EventDetail\RoundsTimelineBuilder;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Services\EventJustJoinedFlash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventTitle;
use SpeedPuzzling\Web\Value\FollowTarget;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EventDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private GetEventAttendance $getEventAttendance,
        readonly private GetEventOffers $getEventOffers,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetEditionRounds $getEditionRounds,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        readonly private CountCompetitionResults $countCompetitionResults,
        readonly private GetCompetitionPageSections $getCompetitionPageSections,
        readonly private ClockInterface $clock,
        readonly private EventPagePuzzles $eventPagePuzzles,
        readonly private RoundsTimelineBuilder $roundsTimelineBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/{slug}',
            'en' => '/en/events/{slug}',
            'es' => '/es/eventos/{slug}',
            'ja' => '/ja/イベント/{slug}',
            'fr' => '/fr/evenements/{slug}',
            'de' => '/de/veranstaltungen/{slug}',
        ],
        name: 'event_detail',
    )]
    public function __invoke(
        // An edition's slug is unique only within its series - a standalone event holding the slug wins
        #[MapEntity(expr: 'repository.findOneBy({"slug": slug}, {"series": "DESC"})')] Competition $competition,
        #[CurrentUser] null|UserInterface $user,
        Request $request,
    ): Response {
        if ($competition->series !== null && $competition->series->slug !== null && $competition->slug !== null) {
            return $this->redirectToRoute('edition_detail', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $competitionId = $competition->id->toString();
        $competitionEvent = $this->getCompetitionEvents->byId($competitionId);
        $rounds = array_values($this->getEditionRounds->forCompetition($competitionId));
        $now = $this->clock->now();
        $eventTitle = EventTitle::forCompetition($competitionEvent, null, $rounds, $now);
        $isPubliclyVisible = $this->isCompetitionPubliclyVisible->check($competitionId);

        // Puzzles outside the rounds (tagged, else - without rounds - the ones people logged times for); a round's
        // puzzles are in its round on the timeline
        $puzzles = $this->eventPagePuzzles->resolve($competitionEvent, $rounds);

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        // "Add my time from this event" deep link: signed-in, the event is publicly visible (so the
        // add-time picker offers it) and it has already started — no times for an upcoming event.
        $canAddTime = $loggedPlayer !== null
            && $competitionEvent->startsAfter($now) === false
            && $isPubliclyVisible;

        // Round results pages of an event that is not public answer 404 - no links to them; only rounds with something
        // to show get one
        $resultsPerRound = $isPubliclyVisible && array_any($rounds, static fn (EditionRoundDetail $round): bool => $round->slug !== null)
            ? $this->countCompetitionResults->perRound($competitionId, $competitionEvent->hasPublishedOfficialResults)
            : [];

        $reference = new CompetitionReference(name: $competitionEvent->name, slug: $competitionEvent->slug);

        $timeline = $this->roundsTimelineBuilder->build(
            event: $reference,
            competitionId: $competitionId,
            rounds: $rounds,
            isOnline: $competitionEvent->isOnline,
            isPublic: $isPubliclyVisible,
            resultsPerRound: $resultsPerRound,
            canAddTime: $canAddTime,
            dateFrom: $competitionEvent->dateFrom,
            dateTo: $competitionEvent->dateTo,
            now: $now,
        );

        $attendance = $this->getEventAttendance->forEvent($competitionEvent, $loggedPlayer?->playerId, $isPubliclyVisible);

        // Marketplace card: one query on a marketplace event, none anywhere else (docs/features/marketplace/11-events.md)
        $eventOffers = $this->getEventOffers->forEventPage($competitionEvent, $isPubliclyVisible, $loggedPlayer?->playerId);

        return $this->render('event_detail.html.twig', [
            'event' => $competitionEvent,
            'event_title' => $eventTitle,
            // Only a past event's meta description quotes the number of results
            'results_count' => $eventTitle->isPast ? $this->countCompetitionResults->forCompetition($competitionId, $competitionEvent->hasPublishedOfficialResults) : 0,
            'is_publicly_visible' => $isPubliclyVisible,
            'online' => $competitionEvent->isOnline,
            'event_place' => EventRowFactory::place($competitionEvent->isOnline, $competitionEvent->location, $competitionEvent->locationCountryCode, $request->getLocale()),
            // An anchor per round: the events page links a session (rounds on separate days) to #round-<id>
            'rounds' => $rounds,
            'timeline' => $timeline,
            'puzzles' => $puzzles,
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList(EventPagePuzzles::difficultyIds($rounds, $puzzles)),
            'puzzle_statuses' => $puzzleStatuses,
            'attendance' => $attendance,
            'can_add_time' => $canAddTime,
            // A one-time event's star follows itself - not once it is over, only on a public page
            'follow_target' => $isPubliclyVisible && $eventTitle->isPast === false ? FollowTarget::competition($competitionId) : null,
            'following' => $attendance->isFollowing,
            'manage' => new ManageRef(ManageRef::KIND_COMPETITION, $competitionId, $competitionEvent->name),
            // Deleting the event from its ⋯ returns to the events page
            'delete_return' => $this->generateUrl('events'),
            'event_offers' => $eventOffers,
            'event_offers_just_joined' => $eventOffers !== null && EventJustJoinedFlash::take($request, $competitionId),
            // Organiser-written sections: queried only when one shows - a page without them runs what it ran before. Only
            // on a publicly visible event: nothing an organiser writes is published before the event is approved
            'page_sections' => $competitionEvent->hasPageSections && $isPubliclyVisible
                ? $this->getCompetitionPageSections->forCompetitionPage($competitionId)
                : [],
        ]);
    }
}
