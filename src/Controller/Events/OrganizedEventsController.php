<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Events;

use DateTimeImmutable;
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
 * "You organize" (docs/features/events-page/README.md, docs/features/organizations/README.md P9): the viewer's
 * organizations first, each with its series and one-time events under it, then every other event and series the viewer
 * created or maintains - with its status and its actions. The count is the one of the page header's "You organize (n)"
 * button (EventsViewerData::organizedCount()): organizations plus the items not under one of them.
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
        $ownOrganizationIds = $viewer->organizedOrganizationIds();
        $items = $this->getOrganizedEvents->byIds(
            $viewer->organizedCompetitionIds(),
            $viewer->organizedSeriesIds(),
            $ownOrganizationIds,
        );

        $organizations = [];
        /** @var array<string, list<OrganizedEvent>> $underOrganization */
        $underOrganization = [];
        $rest = [];

        foreach ($items as $item) {
            if ($item->isOrganization()) {
                $organizations[] = $item;
            } elseif ($item->organizationId !== null && in_array($item->organizationId, $ownOrganizationIds, true)) {
                $underOrganization[$item->organizationId][] = $item;
            } else {
                $rest[] = $item;
            }
        }

        $groups = [];

        foreach (self::sorted($organizations, $today) as $organization) {
            $groups[] = [
                'organization' => $organization,
                'items' => self::sorted($underOrganization[$organization->id] ?? [], $today),
            ];
        }

        return $this->render('events/organized.html.twig', [
            'groups' => $groups,
            'items' => self::sorted($rest, $today),
            'total' => count($groups) + count($rest),
            'today' => $today,
        ]);
    }

    /**
     * What needs attention first, then what is coming, then the rest - by name within each
     *
     * @param list<OrganizedEvent> $items
     *
     * @return list<OrganizedEvent>
     */
    private static function sorted(array $items, DateTimeImmutable $today): array
    {
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

        return $items;
    }
}
