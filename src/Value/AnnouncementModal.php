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
    case FreeTrialOffer = 'free_trial_offer';

    public function template(): string
    {
        return 'modals/announcements/_' . $this->value . '.html.twig';
    }
}
