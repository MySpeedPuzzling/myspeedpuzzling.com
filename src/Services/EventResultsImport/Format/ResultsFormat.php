<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport\Format;

use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How one organizer's results sheet is read - the only per-organizer code of the results import
 * (docs/features/event-results-import/results-import-page.md "Three layers"). Everything after parse() is shared and
 * knows no format. Pure: no database, no clock, no network - what the reader fetched is all it sees.
 */
#[AutoconfigureTag]
interface ResultsFormat
{
    /**
     * Stored on the results source (event_results_sheet.format) - never change a key once a source uses it.
     */
    public function key(): string;

    /**
     * For admins (internal API, console) - the organizer's name of the format.
     */
    public function name(): string;

    /**
     * The Twig partial that tells the organizer how their sheet must look, with an example.
     */
    public function guideTemplate(): string;

    /**
     * Every event the sheet holds, each with a stable key (a re-import matches by it) and its problems. Decides how
     * tabs map to events.
     *
     * @return list<ParsedEvent>
     */
    public function parse(SheetSnapshot $sheet): array;
}
