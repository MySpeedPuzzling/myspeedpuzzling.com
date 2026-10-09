<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Turn into an organization" (create_organization_from_series, docs/features/organizations/README.md "Restructuring
 * tools") - prefilled from the series. Limits as OrganizationFormData (P14).
 */
final class CreateOrganizationFromSeriesFormData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public null|string $name = null,
        #[Assert\Length(max: 30)]
        public null|string $shortName = null,
        // The organization's address - empty makes it from the name
        #[Assert\Length(max: 255)]
        public null|string $slug = null,
        public null|OrganizationKind $kind = null,
        public null|string $countryCode = null,
        #[Assert\Length(max: 120)]
        public null|string $region = null,
        // The series' name and address from now on - empty or unchanged keeps them
        #[Assert\Length(max: 250)]
        public null|string $newSeriesName = null,
        #[Assert\Length(max: 255)]
        public null|string $newSeriesSlug = null,
    ) {
    }

    public static function fromSeries(CompetitionSeries $series): self
    {
        return new self(
            name: $series->name,
            countryCode: $series->locationCountryCode,
            region: Organization::fittedRegion($series->location),
            newSeriesName: $series->name,
            newSeriesSlug: $series->slug,
        );
    }
}
