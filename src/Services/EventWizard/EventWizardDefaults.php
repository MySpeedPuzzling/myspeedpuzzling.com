<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the one-round path infers instead of asking (W-creation-wizard-research.md §3.4) - every value is shown on the
 * Review step with a way to change it:
 *
 * - the round's name is its category ("Solo", "Pairs", "Teams") in the organiser's language - the event's name would
 *   repeat it on every page that shows "event - round"; it follows the category while nobody renamed it;
 * - the time limit starts at 90 minutes, editable on the Puzzle step;
 * - the reveal delay is the round default (RoundPuzzleReveal::DEFAULT_DELAY_MINUTES), the badge colour automatic.
 */
readonly final class EventWizardDefaults
{
    public const int MINUTES_LIMIT = 90;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function roundName(RoundCategory $category): string
    {
        return $this->translator->trans('event_wizard.round_name.' . $category->value);
    }
}
