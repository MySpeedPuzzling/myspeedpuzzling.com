<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Results\SeatingListName;

/**
 * The two lists of the printed seating (docs/features/competitions-management/seating.md).
 */
final readonly class SeatingPrintList
{
    /**
     * By table number; entrants without a table last, by name.
     *
     * @param list<RoundResultEntry> $entries
     * @return list<RoundResultEntry>
     */
    public static function byTable(array $entries): array
    {
        usort($entries, static fn (RoundResultEntry $a, RoundResultEntry $b): int => [$a->tableNumber === null, $a->tableNumber] <=> [$b->tableNumber === null, $b->tableNumber]
            ?: strcasecmp($a->displayName(), $b->displayName())
            ?: strcmp($a->ref->id, $b->ref->id));

        return $entries;
    }

    /**
     * Every person alphabetically (as the organiser recorded the names) with their table - each member of a pair/team
     * on a line of their own, pointing to the pair's/team's table.
     *
     * @param list<RoundResultEntry> $entries
     * @return list<SeatingListName>
     */
    public static function byName(array $entries, string $locale): array
    {
        $names = [];

        foreach ($entries as $entry) {
            if ($entry->kind === RoundResultEntry::KIND_PERSON) {
                $names[] = new SeatingListName(
                    name: $entry->name ?? '',
                    country: $entry->country,
                    tableNumber: $entry->tableNumber,
                    teamName: null,
                    partners: [],
                );

                continue;
            }

            foreach ($entry->members as $member) {
                $names[] = new SeatingListName(
                    name: $member->name,
                    country: $member->country,
                    tableNumber: $entry->tableNumber,
                    teamName: $entry->name,
                    partners: array_values(array_map(
                        static fn (RoundResultEntryMember $partner): string => $partner->name,
                        array_filter($entry->members, static fn (RoundResultEntryMember $partner): bool => $partner->participantId !== $member->participantId),
                    )),
                );
            }
        }

        $collator = \Collator::create($locale);

        usort($names, static function (SeatingListName $a, SeatingListName $b) use ($collator): int {
            $compared = $collator?->compare($a->name, $b->name);

            return (is_int($compared) ? $compared : strcasecmp($a->name, $b->name))
                ?: [$a->tableNumber === null, $a->tableNumber] <=> [$b->tableNumber === null, $b->tableNumber];
        });

        return $names;
    }
}
