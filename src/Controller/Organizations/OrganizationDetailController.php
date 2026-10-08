<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use SpeedPuzzling\Web\Exceptions\DraftNotVisible;
use SpeedPuzzling\Web\Query\GetOrganization;
use SpeedPuzzling\Web\Results\DraftState;
use SpeedPuzzling\Web\Security\OrganizationEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The organization page (docs/features/organizations/README.md "Organization page") - SKELETON of the foundation:
 * the route, the draft guard and the minimum markup. Workstream A builds the page (OrganizationPageBuilder, the
 * sections, SEO). A draft is 404 for everyone but its team and admins (DraftNotVisible); one waiting for approval or
 * rejected is reachable, `noindex` (P12).
 */
final class OrganizationDetailController extends AbstractController
{
    public function __construct(
        readonly private GetOrganization $getOrganization,
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
    public function __invoke(string $slug): Response
    {
        $organization = $this->getOrganization->bySlug($slug);

        // The voter is asked only for a draft - a public page pays no statement for it
        if ($organization->isDraft && $this->isGranted(OrganizationEditVoter::ORGANIZATION_EDIT, $organization->id) === false) {
            throw new DraftNotVisible();
        }

        return $this->render('organization_detail.html.twig', [
            'organization' => $organization,
            'draft_state' => $organization->isDraft
                ? new DraftState(DraftState::KIND_ORGANIZATION, $organization->id, $organization->name, true)
                : null,
        ]);
    }
}
