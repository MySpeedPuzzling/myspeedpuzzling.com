<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EventsController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy',
            'en' => '/en/events',
            'es' => '/es/eventos',
            'ja' => '/ja/イベント',
            'fr' => '/fr/evenements',
            'de' => '/de/veranstaltungen',
        ],
        name: 'events',
    )]
    public function __invoke(): Response
    {
        $playerCompetitions = [];
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile !== null) {
            $playerCompetitions = $this->getCompetitionEvents->allForPlayer($profile->playerId);
        }

        return $this->render('events.html.twig', [
            'live_events' => $this->getCompetitionEvents->allLive(),
            'upcoming_events' => $this->getCompetitionEvents->allUpcoming(),
            'past_events' => $this->getCompetitionEvents->allPast(),
            'player_competitions' => $playerCompetitions,
        ]);
    }
}
