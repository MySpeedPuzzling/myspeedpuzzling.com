<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\EventWizard\EventWizardResume;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The event creation wizard in templates (W2, docs/features/competitions-management/toolkit/W-creation-wizard-research.md):
 * - `event_wizard_resumable(isDraft, isEdition, roundsCount)` - "Continue setting up" from data the page has (no statement);
 * - `event_wizard_resumable_event(competitionId)` - the same by id (one statement, only for whoever edits it).
 */
final class EventWizardTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private EventWizardResume $resume,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('event_wizard_resumable', $this->resume->offered(...)),
            new TwigFunction('event_wizard_resumable_event', $this->resume->offeredFor(...)),
            new TwigFunction('event_wizard_resumable_series', $this->resume->seriesOffered(...)),
            new TwigFunction('event_wizard_resumable_series_id', $this->resume->seriesOfferedFor(...)),
        ];
    }
}
