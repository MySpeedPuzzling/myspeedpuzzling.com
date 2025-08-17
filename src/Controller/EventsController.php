<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EventsController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy',
            'en' => '/en/events',
            'es' => '/es/eventos',
        ],
        name: 'events',
    )]
    public function __invoke(): Response
    {
        return $this->render('events.html.twig', [
            'live_events' => $this->getCompetitionEvents->allLive(),
            'upcoming_events' => $this->getCompetitionEvents->allUpcoming(),
            'past_events' => $this->getCompetitionEvents->allPast(),
        ]);
    }
}
