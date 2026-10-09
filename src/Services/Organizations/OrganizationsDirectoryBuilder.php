<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Organizations;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\OrganizationDirectoryRow;
use SpeedPuzzling\Web\Results\Organizations\OrganizationsDirectory;
use SpeedPuzzling\Web\Results\Organizations\OrganizationsDirectoryItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The organizations directory (docs/features/organizations/README.md "Directory"): the publicly visible organizations
 * (GetOrganizations::publicDirectory(), alphabetically) with the next date of any of their public series and one-time
 * events, from their occurrences (GetEventOccurrences::forOrganizations()) - the series lines' rule
 * (OrganizationPageBuilder::nextOf()).
 */
readonly final class OrganizationsDirectoryBuilder
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<OrganizationDirectoryRow> $organizations
     * @param list<EventOccurrence> $occurrences
     */
    public function build(array $organizations, array $occurrences, DateTimeImmutable $now, string $locale): OrganizationsDirectory
    {
        /** @var array<string, list<EventOccurrence>> $byOrganization */
        $byOrganization = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence->organization !== null) {
                $byOrganization[strtolower($occurrence->organization->id)][] = $occurrence;
            }
        }

        $items = [];

        foreach ($organizations as $organization) {
            $items[] = new OrganizationsDirectoryItem(
                id: $organization->id,
                name: $organization->name,
                shortName: $organization->shortName,
                url: $this->urlGenerator->generate('organization_detail', ['slug' => $organization->slug]),
                logo: $organization->logo,
                kind: $organization->kind,
                place: OrganizationPageBuilder::placeOf($organization->region, $organization->countryCode, $locale),
                seriesCount: $organization->seriesCount,
                eventCount: $organization->eventCount,
                next: OrganizationPageBuilder::nextOf($byOrganization[strtolower($organization->id)] ?? [], $now),
            );
        }

        return new OrganizationsDirectory($items);
    }
}
