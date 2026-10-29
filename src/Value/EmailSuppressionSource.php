<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why an address is on the suppression list (docs/features/mass-mailing/README.md "Suppressed addresses")
 */
enum EmailSuppressionSource: string
{
    /** SES reported a permanent bounce: notifications stop, transactional mail is still tried */
    case HardBounce = 'hard_bounce';

    /** The recipient's domain could not be found in DNS on two bounces a day apart (SES calls them transient) */
    case DomainNotFound = 'domain_not_found';

    /** Put there by hand through the internal API, with a reason the player reads: nothing at all is sent */
    case Manual = 'manual';
}
