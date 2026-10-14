<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Modals the site opens by itself, once per player ever (docs/features/announcement-modals.md).
 * A case owns a template that stays in the code for good - whether and when it shows is decided by
 * its AnnouncementModalRule, never by editing base.html.twig. Order of the cases = priority.
 */
enum AnnouncementModal: string
{
    // The XP / levels / achievements launch (docs/features/xp-levels/launch-2026-10.md): one-time, so it goes first
    case XpLaunchReveal = 'xp_launch_reveal';
    // "What moved" - the navigation redesign's launch (docs/features/navigation LAUNCH-01): after the XP reveal, before
    // any offer
    case NavigationWhatMoved = 'navigation_what_moved';
    case FreeTrialOffer = 'free_trial_offer';

    /**
     * A launch modal is the first thing a player sees after its launch: the pause between two announcement modals
     * (ResolveAnnouncementModal::COOLDOWN_DAYS) does not hold it back - the next modal's pause counts from it. Both
     * launch modals ignore it, so "What moved" follows the XP reveal on the next page (decision 57).
     */
    public function ignoresCooldown(): bool
    {
        return $this === self::XpLaunchReveal || $this === self::NavigationWhatMoved;
    }

    public function template(): string
    {
        return 'modals/announcements/_' . $this->value . '.html.twig';
    }
}
