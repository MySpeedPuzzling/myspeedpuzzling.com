<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
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
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/edice/{competitionId}',
            'en' => '/en/edition/{competitionId}',
            'es' => '/es/edition/{competitionId}',
            'ja' => '/ja/edition/{competitionId}',
            'fr' => '/fr/edition/{competitionId}',
            'de' => '/de/edition/{competitionId}',
        ],
        name: 'edition_detail',
    )]
    public function __invoke(
        string $competitionId,
        #[CurrentUser] null|UserInterface $user,
    ): Response {
        $competition = $this->competitionRepository->get($competitionId);

        if ($competition->series === null) {
            return $this->redirectToRoute('events');
        }

        $competitionEvent = $this->getCompetitionEvents->byId($competitionId);
        $seriesOverview = $this->getCompetitionSeries->byId($competition->series->id->toString());

        $puzzles = [];
        if ($competitionEvent->tagId !== null) {
            $puzzles = $this->getPuzzleOverview->byTagId($competitionEvent->tagId);
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        return $this->render('edition_detail.html.twig', [
            'series' => $seriesOverview,
            'event' => $competitionEvent,
            'puzzles' => $puzzles,
            'puzzle_statuses' => $puzzleStatuses,
        ]);
    }
}
