<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionPageSections;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Query\GetEventOffers;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\DraftState;
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

final class EditionDetailController extends AbstractController
{
    public function __construct(
        readonly private CompetitionRepository $competitionRepository,
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private GetCompetitionSeries $getCompetitionSeries,
        readonly private GetEditionRounds $getEditionRounds,
        readonly private GetEventAttendance $getEventAttendance,
        readonly private GetEventOffers $getEventOffers,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
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
            'cs' => '/serie/{seriesSlug}/{editionSlug}',
            'en' => '/en/series/{seriesSlug}/{editionSlug}',
            'es' => '/es/series/{seriesSlug}/{editionSlug}',
            'ja' => '/ja/series/{seriesSlug}/{editionSlug}',
            'fr' => '/fr/series/{seriesSlug}/{editionSlug}',
            'de' => '/de/series/{seriesSlug}/{editionSlug}',
        ],
        name: 'edition_detail',
    )]
    public function __invoke(
        string $seriesSlug,
        string $editionSlug,
        #[CurrentUser] null|UserInterface $user,
        Request $request,
    ): Response {
        $competition = $this->competitionRepository->getBySeriesAndEditionSlug($seriesSlug, $editionSlug);

        assert($competition->series !== null);

        $competitionId = $competition->id->toString();
        $competitionEvent = $this->getCompetitionEvents->byId($competitionId);
        $seriesOverview = $this->getCompetitionSeries->byId($competition->series->id->toString());

        $rounds = array_values($this->getEditionRounds->forCompetition($competitionId));
        $now = $this->clock->now();
        $eventTitle = EventTitle::forCompetition($competitionEvent, $seriesOverview->name, $rounds, $now);

        // Puzzles outside the rounds (tagged, else - without rounds - the ones people logged times for)
        $puzzles = $this->eventPagePuzzles->resolve($competitionEvent, $rounds);

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        // An edition of an unapproved or rejected series (or a rejected edition) is reachable at its URL,
        // but it is not public: no index, no "Add my time" (the add-time picker would not offer it), no round results links
        $isPubliclyVisible = $this->isCompetitionPubliclyVisible->check($competitionId);

        // "Add my time from this event" deep link: signed-in, the edition is publicly visible and it has
        // already started.
        $canAddTime = $loggedPlayer !== null
            && $competitionEvent->startsAfter($now) === false
            && $isPubliclyVisible;

        // Round results links: only on a public page, only for rounds with something to show
        $resultsPerRound = $isPubliclyVisible && array_any($rounds, static fn (EditionRoundDetail $round): bool => $round->slug !== null)
            ? $this->countCompetitionResults->perRound($competitionId, $competitionEvent->hasPublishedOfficialResults)
            : [];

        $reference = new CompetitionReference(
            name: $competitionEvent->name,
            slug: $competitionEvent->slug,
            seriesName: $seriesOverview->name,
            seriesSlug: $seriesOverview->slug,
        );

        $timeline = $this->roundsTimelineBuilder->build(
            event: $reference,
            competitionId: $competitionId,
            rounds: $rounds,
            isOnline: $seriesOverview->isOnline,
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

        return $this->render('edition_detail.html.twig', [
            'series' => $seriesOverview,
            'event' => $competitionEvent,
            'event_title' => $eventTitle,
            // Only a past edition's meta description quotes the number of results
            'results_count' => $eventTitle->isPast ? $this->countCompetitionResults->forCompetition($competitionId, $competitionEvent->hasPublishedOfficialResults) : 0,
            'is_publicly_visible' => $isPubliclyVisible,
            'online' => $seriesOverview->isOnline,
            'event_place' => EventRowFactory::place(
                $seriesOverview->isOnline,
                $competitionEvent->location ?? $seriesOverview->location,
                $competitionEvent->locationCountryCode ?? $seriesOverview->locationCountryCode,
                $request->getLocale(),
            ),
            'rounds' => $rounds,
            'timeline' => $timeline,
            'puzzles' => $puzzles,
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList(EventPagePuzzles::difficultyIds($rounds, $puzzles)),
            'puzzle_statuses' => $puzzleStatuses,
            'can_add_time' => $canAddTime,
            'attendance' => $attendance,
            // An edition's star follows its series - only on a public page
            'follow_target' => $isPubliclyVisible ? FollowTarget::series($seriesOverview->id) : null,
            'following' => $attendance->isFollowing,
            'manage' => new ManageRef(ManageRef::KIND_COMPETITION, $competitionId, $reference->displayName()),
            // Deleting the edition from its ⋯ returns to the series page
            'delete_return' => $seriesOverview->slug !== null
                ? $this->generateUrl('competition_series_detail', ['slug' => $seriesOverview->slug])
                : $this->generateUrl('events'),
            'event_offers' => $eventOffers,
            'event_offers_just_joined' => $eventOffers !== null && EventJustJoinedFlash::take($request, $competitionId),
            // Organiser-written sections: queried only when one shows - a page without them runs what it ran before. Only
            // on a publicly visible event: nothing an organiser writes is published before the event is approved
            'page_sections' => $competitionEvent->hasPageSections && $isPubliclyVisible
                ? $this->getCompetitionPageSections->forCompetitionPage($competitionId)
                : [],
            // The byline (docs/features/organizations/README.md): an edition is organized by its series' organization,
            // "Who can enter" is its own, else its series'
            'organization' => $seriesOverview->organization,
            'eligibility' => $competitionEvent->eligibility ?? $seriesOverview->eligibility,
            // The edition is a draft and/or its series is one (P7)
            'draft_state' => $competition->isDraft || $seriesOverview->isDraft
                ? new DraftState(
                    kind: DraftState::KIND_COMPETITION,
                    id: $competitionId,
                    name: $competitionEvent->name,
                    isDraft: $competition->isDraft,
                    seriesId: $seriesOverview->isDraft ? $seriesOverview->id : null,
                    seriesName: $seriesOverview->isDraft ? $seriesOverview->name : null,
                )
                : null,
        ]);
    }
}
