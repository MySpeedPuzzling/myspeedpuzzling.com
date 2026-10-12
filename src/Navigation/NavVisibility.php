<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Who sees an entry (navigation README §4.12: rows a viewer cannot use are hidden, never locked - D13). Everything is
 * decided from the viewer's profile row, the XP gate and the voters, never by an extra statement per page.
 *
 * One value per entry, each case one complete audience - a case that needs several conditions names all of them, so a
 * consumer (the Menu, the page search's audience tags, the generated mirror) never has to combine values. Only
 * Everyone, GuestOnly and XpVisible can include guests (includesGuests()); every personal row - your own pages, the
 * account, the roles - uses a case that implies a signed-in viewer (NavigationRegistryTest).
 */
enum NavVisibility: string
{
    // Guests and players
    case Everyone = 'everyone';
    // Guests only (the guest card, Join free, the old guest Hub link)
    case GuestOnly = 'guest';
    // Any signed-in player
    case SignedIn = 'signed_in';
    // Signed in with an active membership
    case Member = 'member';
    // Signed in: members, or anyone who joined the referral programme (PlayerProfile::isInReferralProgram())
    case MemberOrReferral = 'member_or_referral';
    // Signed-in members with a private profile (the allow list only matters then)
    case PrivateMember = 'private_member';
    // The XP system is visible to the viewer (XpFeatureGate) - guests included once XP is launched: public XP pages only
    case XpVisible = 'xp_visible';
    // Signed in and the XP system is visible: a page of the viewer's own behind the XP gate (achievements, your week)
    case XpPlayer = 'xp_player';
    // Signed in, the XP system is visible and the viewer did not opt out of it
    case XpParticipant = 'xp_participant';
    // Signed in, the XP system is visible (karma launches with XP behind XpFeatureGate), the viewer has karma and did
    // not hide it
    case KarmaHolder = 'karma_holder';
    // Signed in and maintains or created an event, series or organization (the "You organize" rule)
    case Organiser = 'organiser';
    // PUZZLE_MODERATION_ACCESS (moderators and admins)
    case Moderator = 'moderator';
    // REVIEW_SUSPICIOUS_TIMES
    case TimeVerifier = 'time_verifier';
    // ADMIN_ACCESS
    case Admin = 'admin';

    /**
     * Can a guest (no player) be in this audience? Personal rows must answer no.
     */
    public function includesGuests(): bool
    {
        return match ($this) {
            self::Everyone, self::GuestOnly, self::XpVisible => true,
            self::SignedIn, self::Member, self::MemberOrReferral, self::PrivateMember, self::XpPlayer, self::XpParticipant,
            self::KarmaHolder, self::Organiser, self::Moderator, self::TimeVerifier, self::Admin => false,
        };
    }

    /**
     * Moderator and admin rows keep English labels - the pages they open are English-only (README §4.12).
     */
    public function isRole(): bool
    {
        return in_array($this, [self::Moderator, self::TimeVerifier, self::Admin], true);
    }

    /**
     * Who that is, in English, for the generated mirror (navigation.md).
     */
    public function docDescription(): string
    {
        return match ($this) {
            self::Everyone => 'guests and players',
            self::GuestOnly => 'guests only',
            self::SignedIn => 'signed-in players',
            self::Member => 'signed-in members',
            self::MemberOrReferral => 'signed-in members or referral programme partners',
            self::PrivateMember => 'signed-in members with a private profile',
            self::XpVisible => 'whoever sees the XP system (guests too, once launched): public XP pages',
            self::XpPlayer => 'signed-in players who see the XP system',
            self::XpParticipant => 'signed-in players who see the XP system and did not opt out',
            self::KarmaHolder => 'signed-in players who see the XP system, have karma and did not hide it',
            self::Organiser => 'signed-in organisers of an event, series or organization',
            self::Moderator => 'moderators and admins',
            self::TimeVerifier => 'time verifiers',
            self::Admin => 'admins',
        };
    }
}
