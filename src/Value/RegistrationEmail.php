<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The e-mails of managed registration (docs/features/competitions-management/registration.md) - the template is
 * `emails/competition_registration_<value>.html.twig`, the texts `competition_registration.<value>.*` in emails.*.yml.
 */
enum RegistrationEmail: string
{
    case Reserved = 'reserved';
    case Waitlisted = 'waitlisted';
    case Paid = 'paid';
    case Promoted = 'promoted';
}
