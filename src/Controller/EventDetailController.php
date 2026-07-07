<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionRegistrationOverview;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EventDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private GetCompetitionParticipants $getCompetitionParticipants,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetCompetitionRegistrationOverview $getCompetitionRegistrationOverview,
        readonly private ClockInterface $clock,
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
        #[MapEntity(mapping: ['slug' => 'slug'])] Competition $competition,
        #[CurrentUser] null|UserInterface $user,
    ): Response {
        if ($competition->series !== null && $competition->series->slug !== null && $competition->slug !== null) {
            return $this->redirectToRoute('edition_detail', [
                'seriesSlug' => $competition->series->slug,
                'editionSlug' => $competition->slug,
            ], Response::HTTP_MOVED_PERMANENTLY);
        }

        $competitionEvent = $this->getCompetitionEvents->byId($competition->id->toString());
        $puzzles = [];

        if ($competitionEvent->tagId !== null) {
            $puzzles = $this->getPuzzleOverview->byTagId($competitionEvent->tagId);
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        $playerConnections = [];
        if ($loggedPlayer !== null) {
            $playerConnections = $this->getCompetitionParticipants->getPlayerConnections(
                $competition->id->toString(),
                $loggedPlayer->playerId,
            );
        }

        $registration = $this->getCompetitionRegistrationOverview->forCompetition(
            $competition->id->toString(),
            $loggedPlayer?->playerId,
        );
        $now = $this->clock->now();

        return $this->render('event_detail.html.twig', [
            'event' => $competitionEvent,
            'puzzles' => $puzzles,
            'puzzle_statuses' => $puzzleStatuses,
            'is_going' => count($playerConnections) > 0,
            'registration' => $registration,
            'registration_is_open' => $registration->isOpen($now),
            'registration_opens_future' => $registration->opensInFuture($now),
        ]);
    }
}
