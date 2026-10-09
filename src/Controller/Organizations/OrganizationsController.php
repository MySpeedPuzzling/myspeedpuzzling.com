<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetEventOccurrences;
use SpeedPuzzling\Web\Query\GetOrganizations;
use SpeedPuzzling\Web\Results\OrganizationDirectoryRow;
use SpeedPuzzling\Web\Services\Organizations\OrganizationsDirectoryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The organizations directory (docs/features/organizations/README.md "Directory"): the publicly visible organizations
 * alphabetically, with the counts of their public series and one-time events and the next date of any of them - two
 * statements (the organizations, their occurrences), nothing per viewer. Indexable.
 */
final class OrganizationsController extends AbstractController
{
    public function __construct(
        readonly private GetOrganizations $getOrganizations,
        readonly private GetEventOccurrences $getEventOccurrences,
        readonly private OrganizationsDirectoryBuilder $directoryBuilder,
        readonly private ClockInterface $clock,
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
    public function __invoke(Request $request): Response
    {
        $organizations = $this->getOrganizations->publicDirectory();
        $occurrences = $this->getEventOccurrences->forOrganizations(
            array_map(static fn (OrganizationDirectoryRow $row): string => $row->id, $organizations),
        );

        return $this->render('organizations.html.twig', [
            'directory' => $this->directoryBuilder->build($organizations, $occurrences, $this->clock->now(), $request->getLocale()),
        ]);
    }
}
