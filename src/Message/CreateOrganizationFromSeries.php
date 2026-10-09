<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OrganizationKind;

/**
 * "Turn into an organization" (docs/features/organizations/README.md "Restructuring tools"): a series used as an
 * organiser's bucket becomes an organization - its name, logo, about (the description), website (the link), country and
 * team are copied, the series' followers follow the organization, and the series is attached to it, optionally renamed
 * and with a new slug. With a new series slug, the old series address redirects to the organization and every old
 * edition and round results address to where it is now. The caller checks the actor may edit the series.
 */
readonly final class CreateOrganizationFromSeries
{
    public function __construct(
        public string $seriesId,
        public UuidInterface $organizationId,
        public string $actingPlayerId,
        public string $name,
        public null|string $shortName,
        // The organization's slug (validated, free among organizations) - null generates it from the name. It may be the
        // series' current slug: organizations and series have separate addresses
        public null|string $slug,
        public null|OrganizationKind $kind,
        // Taken as given - null = none (the web form is prefilled from the series; the internal API sends the series'
        // country when the field is left out)
        public null|string $countryCode,
        // Taken as given - null = none (likewise; the internal API sends the series' location when left out)
        public null|string $region,
        // Approved at once (the internal API, an admin on the web page) - else it waits for an admin, who is e-mailed
        public bool $approve,
        // The series' new name - null keeps it
        public null|string $newSeriesName = null,
        // The series' new slug (validated, free among series) - null keeps it
        public null|string $newSeriesSlug = null,
    ) {
    }
}
