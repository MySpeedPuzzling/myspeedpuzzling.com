<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionRegistrationOverview;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetCompetitionParticipants $getCompetitionParticipants,
        readonly private GetCompetitionRegistrationOverview $getCompetitionRegistrationOverview,
        readonly private GetRoundResults $getRoundResults,
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
    ): Response {
        $competition = $this->competitionRepository->getBySeriesAndEditionSlug($seriesSlug, $editionSlug);

        assert($competition->series !== null);

        $competitionId = $competition->id->toString();
        $competitionEvent = $this->getCompetitionEvents->byId($competitionId);
        $seriesOverview = $this->getCompetitionSeries->byId($competition->series->id->toString());

        $rounds = $this->getEditionRounds->forCompetition($competitionId);

        $puzzles = [];
        if ($competitionEvent->tagId !== null) {
            $puzzles = $this->getPuzzleOverview->byTagId($competitionEvent->tagId);
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        $playerConnections = [];
        if ($loggedPlayer !== null) {
            $playerConnections = $this->getCompetitionParticipants->getPlayerConnections(
                $competitionId,
                $loggedPlayer->playerId,
            );
        }

        $registration = $this->getCompetitionRegistrationOverview->forCompetition(
            $competitionId,
            $loggedPlayer?->playerId,
        );
        $now = $this->clock->now();

        return $this->render('edition_detail.html.twig', [
            'series' => $seriesOverview,
            'event' => $competitionEvent,
            'rounds' => $rounds,
            'puzzles' => $puzzles,
            'puzzle_statuses' => $puzzleStatuses,
            'is_going' => count($playerConnections) > 0,
            'registration' => $registration,
            'registration_is_open' => $registration->isOpen($now),
            'registration_opens_future' => $registration->opensInFuture($now),
            'published_results' => $this->getRoundResults->publishedStandingsForCompetition($competitionId),
        ]);
    }
}
