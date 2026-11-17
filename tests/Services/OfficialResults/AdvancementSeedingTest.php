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
     * An advancement step's "top N over all source rounds" (competition toolkit A8): the seed order, the last place that
     * fits shared = everybody with the same place, finished state and result relative to their winner.
     */
    public function testTopOverallTakesTheBestNBySeedAndHighlightsTheTieAtTheCut(): void
    {
        $rounds = [
            'group-a' => self::round('group-a', [
                'a1' => RoundEntryResult::finished(3600),
                'a2' => RoundEntryResult::finished(3960),
                'a3' => RoundEntryResult::unfinished(900),
            ]),
            'group-b' => self::round('group-b', [
                'b1' => RoundEntryResult::finished(4000),
                'b2' => RoundEntryResult::finished(4200),
                'b3' => RoundEntryResult::finished(4800),
                'bx' => RoundEntryResult::didNotStart(),
            ]),
        ];
        $pieces = ['group-a' => 1000, 'group-b' => 1000];
        $names = static fn (array $top): array => [
            array_map(static fn (SeededEntry $entry): null|string => $entry->entry->name, $top['selected']),
            array_map(static fn (SeededEntry $entry): null|string => $entry->entry->name, $top['tiedAtCut']),
        ];

        self::assertSame([['a1', 'b1', 'b2'], []], $names(AdvancementSeeding::topOverall($rounds, $pieces, 3)));
        // Both winners share the first place (rank 1, each 1.0 × their winner): two places, no tie past the cut
        self::assertSame([['a1', 'b1'], []], $names(AdvancementSeeding::topOverall($rounds, $pieces, 2)));
        // One place, two winners: both proposed, both highlighted - the organiser decides
        self::assertSame([['a1', 'b1'], ['a1', 'b1']], $names(AdvancementSeeding::topOverall($rounds, $pieces, 1)));
        // Did not start is never proposed
        self::assertSame([['a1', 'b1', 'b2', 'a2', 'b3', 'a3'], []], $names(AdvancementSeeding::topOverall($rounds, $pieces, 100)));
        self::assertSame([[], []], $names(AdvancementSeeding::topOverall($rounds, $pieces, 0)));
        self::assertSame([4, 5], array_map(static fn (SeededEntry $entry): int => $entry->seed, array_slice(AdvancementSeeding::topOverall($rounds, $pieces, 5)['selected'], 3)));
    }

    public function testTopOverallTreatsASharedPlaceInsideOneRoundAsATie(): void
    {
        $rounds = [
            'group-a' => self::round('group-a', ['amy' => RoundEntryResult::finished(100), 'zed' => RoundEntryResult::finished(100), 'cid' => RoundEntryResult::finished(150)]),
        ];

        $top = AdvancementSeeding::topOverall($rounds, [], 1);

        self::assertSame(['amy', 'zed'], array_map(static fn (SeededEntry $entry): null|string => $entry->entry->name, $top['selected']));
        self::assertSame(['amy', 'zed'], array_map(static fn (SeededEntry $entry): null|string => $entry->entry->name, $top['tiedAtCut']));
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
