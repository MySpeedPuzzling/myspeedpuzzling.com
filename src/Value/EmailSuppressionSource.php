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

    /**
     * The inbox was full on MailboxFullRule::BOUNCES bounces within MailboxFullRule::WITHIN_DAYS: notifications pause
     * until a delivery to the address succeeds or MailboxFullRule::EXPIRES_AFTER_DAYS pass (a full inbox clears up)
     */
    case MailboxFull = 'mailbox_full';

    /**
     * The person marked a claim invitation as spam (docs/features/competitions-management/participant-linking.md R8): no
     * more claim invitations to the address from any event - and nothing else changes (Jan's rule: a complaint switches
     * off only what was complained about). Holds back no other mail, shows no banner (isInEffect(), sqlInEffect()); a
     * real bounce later turns it into that source.
     */
    case InvitationComplaint = 'invitation_complaint';

    /** Put there by hand through the internal API, with a reason the player reads: nothing at all is sent */
    case Manual = 'manual';
}
