<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Events;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Query\GetOrganizedEvents;
use SpeedPuzzling\Web\Results\OrganizedEvent;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\OrganizerBadge;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "You organize" (docs/features/events-page/README.md): every event and series the viewer created or maintains, with
 * its approval status. The same rule as the page header's "You organize (n)" button (EventsViewerData).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class OrganizedEventsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetEventsViewerData $getEventsViewerData,
        readonly private GetOrganizedEvents $getOrganizedEvents,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/you-organize',
        name: 'organized_events',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('events');
        }

        $viewer = $this->getEventsViewerData->forPlayer($profile->playerId);
        $today = $this->clock->now();
        $items = $this->getOrganizedEvents->byIds($viewer->organizedCompetitionIds(), $viewer->organizedSeriesIds());

        // What needs attention first, then what is coming, then the rest
        $rank = static fn (OrganizedEvent $item): int => match ($item->badge($today)) {
            OrganizerBadge::Rejected => 0,
            OrganizerBadge::Draft => 1,
            OrganizerBadge::WaitingForApproval => 2,
            OrganizerBadge::Live => 3,
            OrganizerBadge::Upcoming => 4,
            OrganizerBadge::DateNotSet => 5,
            OrganizerBadge::Past => 6,
        };

        usort($items, static fn (OrganizedEvent $a, OrganizedEvent $b): int => $rank($a) <=> $rank($b)
            ?: strcmp(SearchText::fold($a->name), SearchText::fold($b->name)));

        return $this->render('events/organized.html.twig', [
            'items' => $items,
            'today' => $today,
        ]);
    }
}
