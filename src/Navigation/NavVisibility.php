<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Who sees an entry (navigation README §4.12: rows a viewer cannot use are hidden, never locked - D13). Everything is
 * decided from the viewer's profile row, the XP gate and the voters, never by an extra statement per page.
 */
enum NavVisibility: string
{
    case Everyone = 'everyone';
    case GuestOnly = 'guest';
    case SignedIn = 'signed_in';
    case Member = 'member';
    // Members, or anyone who joined the referral programme (PlayerProfile::isInReferralProgram())
    case MemberOrReferral = 'member_or_referral';
    // Members with a private profile (the allow list only matters then)
    case PrivateMember = 'private_member';
    // The XP system is visible to the viewer (XpFeatureGate)
    case XpVisible = 'xp_visible';
    // XP visible and the viewer did not opt out of it
    case XpParticipant = 'xp_participant';
    // The viewer has karma and did not hide it
    case KarmaHolder = 'karma_holder';
    // Maintains or created an event, series or organization (the "You organize" rule)
    case Organiser = 'organiser';
    // PUZZLE_MODERATION_ACCESS (moderators and admins)
    case Moderator = 'moderator';
    // REVIEW_SUSPICIOUS_TIMES
    case TimeVerifier = 'time_verifier';
    // ADMIN_ACCESS
    case Admin = 'admin';

    /**
     * Moderator and admin rows keep English labels - the pages they open are English-only (README §4.12).
     */
    public function isRole(): bool
    {
        return in_array($this, [self::Moderator, self::TimeVerifier, self::Admin], true);
    }
}
