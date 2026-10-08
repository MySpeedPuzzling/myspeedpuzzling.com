<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Security\OrganizationEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Edit an organization (docs/features/organizations/README.md "Forms") - SKELETON of the foundation: the route and its
 * access rule (the organization's team, admins). Workstream A builds the form (→ EditOrganization).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditOrganizationController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/upravit-organizaci/{organizationId}',
            'en' => '/en/edit-organization/{organizationId}',
            'es' => '/es/edit-organization/{organizationId}',
            'ja' => '/ja/edit-organization/{organizationId}',
            'fr' => '/fr/edit-organization/{organizationId}',
            'de' => '/de/edit-organization/{organizationId}',
        ],
        name: 'edit_organization',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $organizationId): Response
    {
        $this->denyAccessUnlessGranted(OrganizationEditVoter::ORGANIZATION_EDIT, $organizationId);

        throw new NotFoundHttpException();
    }
}
