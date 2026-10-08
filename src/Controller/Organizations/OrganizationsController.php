<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use SpeedPuzzling\Web\Query\GetOrganizations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The organizations directory (docs/features/organizations/README.md "Directory") - SKELETON of the foundation: the
 * publicly visible organizations as a plain list. Workstream A builds the page (OrganizationsDirectoryBuilder).
 */
final class OrganizationsController extends AbstractController
{
    public function __construct(
        readonly private GetOrganizations $getOrganizations,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/organizace',
            'en' => '/en/organizations',
            'es' => '/es/organizaciones',
            'ja' => '/ja/団体',
            'fr' => '/fr/organisations',
            'de' => '/de/organisationen',
        ],
        name: 'organizations',
    )]
    public function __invoke(): Response
    {
        return $this->render('organizations.html.twig', [
            'organizations' => $this->getOrganizations->publicDirectory(),
        ]);
    }
}
