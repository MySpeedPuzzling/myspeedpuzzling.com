<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\TableLabels;

/**
 * The spotlight picker's chips (competition toolkit v2 L2, displays-standings.md §5.3) from the organiser's entries of a
 * round (GetRoundResultEntries): the organiser's names only (no player, #code or id), the table, a pair/team's members
 * and when its result came in ("Just finished"). Did not start is left out - a display shows nobody for it. On a
 * labelled round (table-labels-and-plates.md A4) a chip also carries `tableLabel` - only when the list names its table.
 */
final readonly class DisplayControlEntries
{
    /**
     * @param list<RoundResultEntry> $entries
     * @param null|TableLabels $labels the round's table labels (RoundResultsOverview::$tableLabels)
     * @return list<array{ref: string, table: null|int, name: string, members: list<string>, finishedAtMs: null|int, tableLabel?: string}>
     */
    public static function forPicker(array $entries, null|TableLabels $labels = null): array
    {
        $chips = [];

        foreach ($entries as $entry) {
            if ($entry->result->didNotStart) {
                continue;
            }

            $chip = [
                'ref' => $entry->ref->toString(),
                'table' => $entry->tableNumber,
                'name' => self::name($entry),
                'members' => self::members($entry),
                'finishedAtMs' => !$entry->result->isNone() && $entry->resultEnteredAt !== null ? RoundClock::milliseconds($entry->resultEnteredAt) : null,
            ];
            $label = self::tableLabel($labels, $entry->tableNumber);

            if ($label !== null) {
                $chip['tableLabel'] = $label;
            }

            $chips[] = $chip;
        }

        return $chips;
    }

    /**
     * The organiser's name - an unnamed pair/team is its members, as on the round page and the displays.
     */
    public static function name(RoundResultEntry $entry): string
    {
        return $entry->name ?? implode(', ', self::members($entry));
    }

    /**
     * The label of a table number on a labelled round - null on a plain-numbers round and for a number the list does not
     * name (the picker then shows the number).
     */
    public static function tableLabel(null|TableLabels $labels, null|int $number): null|string
    {
        return $labels !== null && $number !== null && $labels->names($number) ? $labels->labelOf($number) : null;
    }

    /**
     * @return list<string>
     */
    private static function members(RoundResultEntry $entry): array
    {
        return array_map(static fn (RoundResultEntryMember $member): string => $member->name, $entry->members);
    }
}
