<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Results\PlayerProfile;

/**
 * Who is looking, as the navigation needs it (docs/features/navigation README §4.12, MENU-01): every flag a
 * NavVisibility audience is decided from - the viewer's profile row, the XP gate and the voters, all without a
 * statement of their own (NavigationViewerFactory builds it once per request).
 */
final readonly class NavigationViewer
{
    public function __construct(
        public null|PlayerProfile $profile,
        public bool $member = false,
        public bool $organiser = false,
        public bool $moderator = false,
        public bool $timeVerifier = false,
        public bool $admin = false,
        public bool $xpVisible = false,
        public bool $xpParticipant = false,
        public bool $achievementsShown = false,
        public bool $karmaHolder = false,
        public bool $privateMember = false,
        public bool $memberOrReferral = false,
        // Whole days left of a running free trial, rounded up (the membership status line); null without a trial
        public null|int $trialDaysLeft = null,
    ) {
    }

    public static function forGuest(bool $xpVisible): self
    {
        return new self(null, xpVisible: $xpVisible);
    }

    public function isGuest(): bool
    {
        return $this->profile === null;
    }

    public function playerId(): null|string
    {
        return $this->profile?->playerId;
    }

    /**
     * Is the viewer in the audience? Rows they are not in are not rendered - hidden, never locked (D13).
     */
    public function sees(NavVisibility $visibility): bool
    {
        $signedIn = !$this->isGuest();

        return match ($visibility) {
            NavVisibility::Everyone => true,
            NavVisibility::GuestOnly => !$signedIn,
            NavVisibility::SignedIn => $signedIn,
            NavVisibility::Member => $signedIn && $this->member,
            NavVisibility::MemberOrReferral => $signedIn && $this->memberOrReferral,
            NavVisibility::PrivateMember => $signedIn && $this->privateMember,
            NavVisibility::XpVisible => $this->xpVisible,
            NavVisibility::XpPlayer => $signedIn && $this->xpVisible,
            NavVisibility::XpParticipant => $signedIn && $this->xpParticipant,
            NavVisibility::AchievementsShown => $signedIn && $this->achievementsShown,
            NavVisibility::KarmaHolder => $signedIn && $this->karmaHolder,
            NavVisibility::Organiser => $signedIn && $this->organiser,
            NavVisibility::Moderator => $signedIn && $this->moderator,
            NavVisibility::TimeVerifier => $signedIn && $this->timeVerifier,
            NavVisibility::Admin => $signedIn && $this->admin,
        };
    }

    /**
     * The audiences the page may expose (`<body data-viewer-audience>`, read by the page search, MENU-03): every one
     * the viewer is in, without the role audiences - moderator and admin entries are rendered into the page for those
     * viewers only, never filtered client-side. Identical for every guest, so guest HTML stays shared-cacheable.
     *
     * @return list<string>
     */
    public function pageAudience(): array
    {
        $audience = [];

        foreach (NavVisibility::cases() as $visibility) {
            if ($visibility->isPageFlag() && $this->sees($visibility)) {
                $audience[] = $visibility->value;
            }
        }

        return $audience;
    }
}
