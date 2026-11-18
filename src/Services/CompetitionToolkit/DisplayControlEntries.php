<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * The spotlight picker's chips (competition toolkit v2 L2, displays-standings.md §5.3) from the organiser's entries of a
 * round (GetRoundResultEntries): the organiser's names only (no player, #code or id), the table, a pair/team's members
 * and when its result came in ("Just finished"). Did not start is left out - a display shows nobody for it.
 */
final readonly class DisplayControlEntries
{
    /**
     * @param list<RoundResultEntry> $entries
     * @return list<array{ref: string, table: null|int, name: string, members: list<string>, finishedAtMs: null|int}>
     */
    public static function forPicker(array $entries): array
    {
        $chips = [];

        foreach ($entries as $entry) {
            if ($entry->result->didNotStart) {
                continue;
            }

            $chips[] = [
                'ref' => $entry->ref->toString(),
                'table' => $entry->tableNumber,
                'name' => self::name($entry),
                'members' => self::members($entry),
                'finishedAtMs' => !$entry->result->isNone() && $entry->resultEnteredAt !== null ? RoundClock::milliseconds($entry->resultEnteredAt) : null,
            ];
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
     * @return list<string>
     */
    private static function members(RoundResultEntry $entry): array
    {
        return array_map(static fn (RoundResultEntryMember $member): string => $member->name, $entry->members);
    }
}
