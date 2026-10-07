<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OfficialResults;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\SeededEntry;
use SpeedPuzzling\Web\Services\AdvancementSeeding;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

final class AdvancementSeedingTest extends TestCase
{
    public function testRoundWinnersFirstThenSecondsInterleavedByTheirResultRelativeToTheirWinner(): void
    {
        $seeded = AdvancementSeeding::seed([
            'group-a' => self::round('group-a', [
                'a1' => RoundEntryResult::finished(3600),
                // 1.10 × the winner
                'a2' => RoundEntryResult::finished(3960),
                'a3' => RoundEntryResult::unfinished(900),
            ]),
            'group-b' => self::round('group-b', [
                // A slower winner - still a winner
                'b1' => RoundEntryResult::finished(4000),
                // 1.05 × the winner: a better second than a2, although slower in seconds
                'b2' => RoundEntryResult::finished(4200),
                'b3' => RoundEntryResult::finished(4800),
                'bx' => RoundEntryResult::didNotStart(),
            ]),
        ], ['group-a' => 1000, 'group-b' => 1000]);

        self::assertSame(
            ['a1', 'b1', 'b2', 'a2', 'b3', 'a3', 'bx'],
            array_map(static fn (SeededEntry $entry): null|string => $entry->entry->name, $seeded),
        );
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_map(static fn (SeededEntry $entry): int => $entry->seed, $seeded));
        self::assertSame('group-b', $seeded[1]->sourceRoundId);
    }

    public function testTiesWithinARoundFollowTheOrganisersOrderOfRoundsThenName(): void
    {
        $seeded = AdvancementSeeding::seed([
            'group-a' => self::round('group-a', ['zed' => RoundEntryResult::finished(100), 'amy' => RoundEntryResult::finished(100)]),
            'group-b' => self::round('group-b', ['bob' => RoundEntryResult::finished(200)]),
        ]);

        self::assertSame(['amy', 'zed', 'bob'], array_map(static fn (SeededEntry $entry): null|string => $entry->entry->name, $seeded));
    }

    public function testOnlyIncludedEntriesAreSeeded(): void
    {
        $seeded = AdvancementSeeding::seed(
            ['group-a' => self::round('group-a', ['a1' => RoundEntryResult::finished(100), 'a2' => RoundEntryResult::finished(200)])],
            [],
            static fn (RoundResultEntry $entry): bool => $entry->name === 'a2',
        );

        self::assertCount(1, $seeded);
        self::assertSame(1, $seeded[0]->seed);
        self::assertSame('a2', $seeded[0]->entry->name);
    }

    /**
     * @param array<string, RoundEntryResult> $results name => result
     * @return list<RoundResultEntry>
     */
    private static function round(string $roundId, array $results): array
    {
        $ranks = OfficialResultsRanking::rank($results);
        $entries = [];
        $index = 0;

        foreach ($results as $name => $result) {
            $index++;
            $entries[] = new RoundResultEntry(
                ref: RoundEntryRef::participantRound(sprintf('018d0099-0000-0000-0000-%012d', crc32($roundId . $name) % 1000000 + $index)),
                kind: RoundResultEntry::KIND_PERSON,
                roundId: $roundId,
                name: $name,
                participantId: null,
                country: null,
                countries: [],
                members: [],
                playerId: null,
                playerCode: null,
                playerName: null,
                tableNumber: null,
                result: $result,
                rank: $ranks[$name],
                qualifiedAt: null,
                resultEnteredAt: null,
                resultEnteredById: null,
                resultEnteredByName: null,
            );
        }

        return $entries;
    }
}
