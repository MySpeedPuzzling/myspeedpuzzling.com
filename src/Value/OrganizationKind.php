<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What kind of organization runs the events (docs/features/organizations/README.md, D8) - optional.
 */
enum OrganizationKind: string
{
    // An association or a federation
    case Association = 'association';
    case Club = 'club';
    // Runs events and competitions as its main activity (online or in person)
    case Organizer = 'organizer';
    // A shop or a brand
    case Shop = 'shop';
    case Venue = 'venue';
    case Community = 'community';
    case Other = 'other';

    public function translationKey(): string
    {
        return 'organization.kind.' . $this->value;
    }
}
