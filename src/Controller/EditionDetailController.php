<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetEventAttendance;
use SpeedPuzzling\Web\Query\GetEventOffers;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\EventJustJoinedFlash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventTitle;
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
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPuzzleDifficulty $getPuzzleDifficulty,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        readonly private CountCompetitionResults $countCompetitionResults,
        readonly private ClockInterface $clock,
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

        $rounds = $this->getEditionRounds->forCompetition($competitionId);
        $eventTitle = EventTitle::forCompetition($competitionEvent, $seriesOverview->name, $rounds, $this->clock->now());

        $puzzles = [];
        if ($competitionEvent->tagId !== null) {
            $puzzles = $this->getPuzzleOverview->byTagId($competitionEvent->tagId);
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        // An edition of an unapproved or rejected series (or a rejected edition) is reachable at its URL,
        // but it is not public: no index, no "Add my time" (the add-time picker would not offer it)
        $isPubliclyVisible = $this->isCompetitionPubliclyVisible->check($competitionId);

        // "Add my time from this event" deep link: signed-in, the edition is publicly visible and it has
        // already started.
        $canAddTime = $loggedPlayer !== null
            && $competitionEvent->startsAfter($this->clock->now()) === false
            && $isPubliclyVisible;

        // Marketplace card: one query on a marketplace event, none anywhere else (docs/features/marketplace/11-events.md)
        $eventOffers = $this->getEventOffers->forEventPage($competitionEvent, $isPubliclyVisible, $loggedPlayer?->playerId);

        return $this->render('edition_detail.html.twig', [
            'series' => $seriesOverview,
            'event' => $competitionEvent,
            'event_title' => $eventTitle,
            // Only a past edition's meta description quotes the number of results
            'results_count' => $eventTitle->isPast ? $this->countCompetitionResults->forCompetition($competitionId) : 0,
            'is_publicly_visible' => $isPubliclyVisible,
            'rounds' => $rounds,
            'puzzles' => $puzzles,
            'difficulty_data' => $this->getPuzzleDifficulty->forPuzzleList(array_values(array_map(
                static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
                $puzzles,
            ))),
            'puzzle_statuses' => $puzzleStatuses,
            'can_add_time' => $canAddTime,
            'attendance' => $this->getEventAttendance->forPlayer($competitionId, $loggedPlayer?->playerId),
            'event_offers' => $eventOffers,
            'event_offers_just_joined' => $eventOffers !== null && EventJustJoinedFlash::take($request, $competitionId),
        ]);
    }
}
