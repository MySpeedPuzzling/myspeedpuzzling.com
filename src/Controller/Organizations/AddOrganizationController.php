<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Add an organization (docs/features/organizations/README.md "Forms") - SKELETON of the foundation: the route and its
 * access rule. Workstream A builds the form (OrganizationFormType on OrganizationFormData → AddOrganization).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddOrganizationController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pridat-organizaci',
            'en' => '/en/add-organization',
            'es' => '/es/add-organization',
            'ja' => '/ja/add-organization',
            'fr' => '/fr/add-organization',
            'de' => '/de/add-organization',
        ],
        name: 'add_organization',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        throw new NotFoundHttpException();
    }
}
