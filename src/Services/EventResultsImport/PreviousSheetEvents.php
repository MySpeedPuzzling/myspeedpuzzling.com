<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use SpeedPuzzling\Web\Results\ResultsImport\StoredResultsTab;
use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;
use SpeedPuzzling\Web\Value\EventResultsImport\PreviousSheetMatch;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * The safety net after the organizer changed the source's spreadsheet (docs/features/event-results-import/
 * results-import-page.md "As built: PR 4"): the new sheet's events may have other stable keys (a tab of another sheet
 * has another gid - a duplicated spreadsheet usually keeps them, then nothing here matters). An event with no imported
 * item under its own key is the event imported before when exactly one imported event of the source, stored under a
 * key the sheet no longer has, has its date and name (trimmed, case and accents folded - SearchText::fold()).
 * Two imported events with its date and name, or two events of the sheet matching one imported event, are a problem -
 * never a guess. Format-agnostic: only the neutral model is read. Pure - the page, the import and the sync ask it with
 * what they have already read, so all three decide alike.
 */
readonly final class PreviousSheetEvents
{
    /**
     * @param list<ParsedEvent> $events every event of the read - the keys still in it are never matched to another
     * @param array<array-key, StoredResultsTab> $stored the source's stored items by key (GetResultsImportStoredTabs)
     * @return array<array-key, PreviousSheetMatch> by the event's key - only the events that met an imported one
     */
    public static function match(array $events, array $stored): array
    {
        $keysInSheet = [];

        foreach ($events as $event) {
            $keysInSheet[$event->key] = true;
        }

        // Imported events the sheet no longer has under their key, by their date and folded name
        $candidates = [];

        foreach ($stored as $key => $tab) {
            $key = (string) $key;

            if (isset($keysInSheet[$key]) || $tab->isImported() === false || $tab->competitionName === null || $tab->dateFrom === null) {
                continue;
            }

            $candidates[self::identity($tab->dateFrom->format('Y-m-d'), $tab->competitionName)][] = $key;
        }

        if ($candidates === []) {
            return [];
        }

        /** @var array<array-key, list<string>> $found the event's key => the matching imported keys */
        $found = [];
        /** @var array<array-key, int> $claimedBy imported key => how many events of the sheet match it */
        $claimedBy = [];

        foreach ($events as $event) {
            if ($event->eventName === null || $event->localStartsAt === null || ($stored[$event->key] ?? null)?->isImported() === true) {
                continue;
            }

            $keys = $candidates[self::identity($event->localStartsAt->format('Y-m-d'), $event->eventName)] ?? [];

            if ($keys === []) {
                continue;
            }

            $found[$event->key] = $keys;

            foreach ($keys as $key) {
                $claimedBy[$key] = ($claimedBy[$key] ?? 0) + 1;
            }
        }

        $matches = [];

        foreach ($found as $eventKey => $keys) {
            $eventKey = (string) $eventKey;

            if (count($keys) > 1) {
                $matches[$eventKey] = PreviousSheetMatch::problem(PreviousSheetMatch::MATCHES_SEVERAL_IMPORTED_EVENTS, (string) count($keys));
            } elseif ($claimedBy[$keys[0]] > 1) {
                $matches[$eventKey] = PreviousSheetMatch::problem(PreviousSheetMatch::SHARES_IMPORTED_EVENT);
            } else {
                $matches[$eventKey] = PreviousSheetMatch::of($keys[0], isset($stored[$eventKey]));
            }
        }

        return $matches;
    }

    private static function identity(string $day, string $name): string
    {
        return $day . ' ' . SearchText::fold(trim($name));
    }
}
