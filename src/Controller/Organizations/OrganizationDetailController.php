<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Query\GetEventGoingCounts;
use SpeedPuzzling\Web\Query\GetEventOccurrences;
use SpeedPuzzling\Web\Query\GetEventSeriesDirectory;
use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Query\GetOrganization;
use SpeedPuzzling\Web\Results\DraftState;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Security\OrganizationEditVoter;
use SpeedPuzzling\Web\Services\EventDetail\SeriesPageBuilder;
use SpeedPuzzling\Web\Services\Organizations\OrganizationPageBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The organization page (docs/features/organizations/README.md "Organization page"): the organization, the occurrences
 * of its series and one-time events in one statement, its series, the going counts of the coming ones, and for a
 * signed-in visitor their going/follow rows - OrganizationPageBuilder turns them into the page. Its team (and admins)
 * also see its drafts and the ones waiting for approval, tagged (P6). A draft organization is 404 for everyone but its
 * team (DraftNotVisible); one waiting for approval or rejected is reachable, `noindex`, without the star (P12). No
 * public team list (D19).
 */
final class OrganizationDetailController extends AbstractController
{
    public function __construct(
        readonly private GetOrganization $getOrganization,
        readonly private GetEventOccurrences $getEventOccurrences,
        readonly private GetEventSeriesDirectory $getEventSeriesDirectory,
        readonly private GetEventGoingCounts $getEventGoingCounts,
        readonly private GetEventsViewerData $getEventsViewerData,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private OrganizationPageBuilder $organizationPageBuilder,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/organizace/{slug}',
            'en' => '/en/organizations/{slug}',
            'es' => '/es/organizaciones/{slug}',
            'ja' => '/ja/団体/{slug}',
            'fr' => '/fr/organisations/{slug}',
            'de' => '/de/organisationen/{slug}',
        ],
        name: 'organization_detail',
    )]
    public function __invoke(string $slug, Request $request): Response
    {
        $organization = $this->getOrganization->bySlug($slug);
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        // Its team and admins (the voter answers both) - asked only for a signed-in visitor: guests pay no statement
        $canManage = $profile !== null && $this->isGranted(OrganizationEditVoter::ORGANIZATION_EDIT, $organization->id);

        if ($organization->isDraft && $canManage === false) {
            throw new DraftNotVisible();
        }

        $now = $this->clock->now();
        $viewer = $profile !== null ? $this->getEventsViewerData->forPlayer($profile->playerId) : null;
        $occurrences = $this->getEventOccurrences->forOrganization($organization->id, includeDrafts: $canManage);
        $series = $this->getEventSeriesDirectory->forOrganization($organization->id, includeDrafts: $canManage);

        $page = $this->organizationPageBuilder->build(
            organization: $organization,
            occurrences: $occurrences,
            series: $series,
            goingCounts: $this->getEventGoingCounts->forCompetitions(SeriesPageBuilder::comingCompetitionIds($occurrences, $now)),
            viewer: $viewer,
            now: $now,
            locale: $request->getLocale(),
        );

        return $this->render('organization_detail.html.twig', [
            'organization' => $organization,
            'page' => $page,
            'can_manage' => $canManage,
            'manage' => new ManageRef(ManageRef::KIND_ORGANIZATION, $organization->id, $organization->name),
            'draft_state' => $organization->isDraft
                ? new DraftState(DraftState::KIND_ORGANIZATION, $organization->id, $organization->name, true)
                : null,
            // The ⋯ menu's host: for its team, and for an organiser of one of its series or events (their row ⋯)
            'show_menu' => $viewer !== null && (
                $canManage
                || array_intersect(
                    array_map(static fn (EventOccurrence $occurrence): string => strtolower($occurrence->competitionId), $occurrences),
                    $viewer->organizedCompetitionIds(),
                ) !== []
                || array_intersect(
                    array_map(static fn (EventSeriesRow $row): string => strtolower($row->id), $series),
                    $viewer->organizedSeriesIds(),
                ) !== []
            ),
        ]);
    }
}
