<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One row of the "Get ready" checklist on an event's edit page (W2 of the event creation wizard,
 * docs/features/competitions-management/toolkit/W-creation-wizard-research.md §3.10). Every item is a class of this
 * directory; EventGetReadyChecklist asks all of them, in order(). A new row (W6: one per Extras toggle) is one more class
 * - and, when it needs data the facts lack, one more field on GetReadyFacts.
 *
 * Rules: derived from data only (nothing stored for the checklist); a row exists only when it means something for this
 * event (smart forms) - row() answers null otherwise; pure (no statements, no services that query).
 */
#[AutoconfigureTag]
interface GetReadyItem
{
    /**
     * Its place in the list - lower first
     */
    public function order(): int;

    public function row(GetReadyFacts $facts): null|GetReadyRow;
}
