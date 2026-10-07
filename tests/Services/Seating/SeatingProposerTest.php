<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Seating;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Services\OfficialResultsRanking;
use SpeedPuzzling\Web\Services\SeatingProposer;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\SeatingSource;
use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * The ordering rules of a seating proposal (docs/features/competitions-management/seating.md), without a database.
 */
final class SeatingProposerTest extends TestCase
{
    private const string ROUND = '018d0099-0000-0000-0000-000000000001';

    public function testTheFastestComeFirstThenTiesByNameThenEveryoneWithoutDataByName(): void
    {
        $entries = [self::person('p1', 'Zoe'), self::person('p2', 'Adam'), self::person('p3', 'Bea'), self::person('p4', 'Ann'), self::person('p5', 'Carl')];

        $ordered = SeatingProposer::orderByKey($entries, [
            self::ref('p1') => 3000,
            self::ref('p3') => 2000,
            // Same time as Zoe - by name
            self::ref('p5') => 3000,
        ], false, SeatingProposer::nameComparator('en'));

        self::assertSame(['Bea', 'Carl', 'Zoe', 'Adam', 'Ann'], self::names($ordered));
    }

    public function testSlowestFirstStillPutsTheEntrantsWithoutDataLast(): void
    {
        $entries = [self::person('p1', 'Zoe'), self::person('p2', 'Adam'), self::person('p3', 'Bea'), self::person('p5', 'Carl')];

        $ordered = SeatingProposer::orderByKey($entries, [
            self::ref('p1') => 3000,
            self::ref('p3') => 2000,
            self::ref('p5') => 3000,
        ], true, SeatingProposer::nameComparator('en'));

        self::assertSame(['Carl', 'Zoe', 'Bea', 'Adam'], self::names($ordered));
    }

    public function testNamesAreComparedLikeTheLanguageDoesNotByteByByte(): void
    {
        $entries = [self::person('p1', 'Zuzana'), self::person('p2', 'Šárka'), self::person('p3', 'Adam'), self::person('p4', 'ádám')];

        self::assertSame(['Adam', 'ádám', 'Šárka', 'Zuzana'], self::names(SeatingProposer::orderByKey($entries, [], false, SeatingProposer::nameComparator('en'))));
    }

    public function testARandomDrawIsTheSameForTheSameNumberAndDiffersForAnother(): void
    {
        $entries = [];
        foreach (range(1, 12) as $index) {
            $entries[] = self::person('r' . $index, 'Player ' . $index);
        }

        $draw = self::names(SeatingProposer::orderRandomly($entries, 4821));

        self::assertSame($draw, self::names(SeatingProposer::orderRandomly(array_reverse($entries), 4821)));
        self::assertNotSame($draw, self::names(SeatingProposer::orderRandomly($entries, 4822)));
        self::assertEqualsCanonicalizing(self::names($entries), $draw);
    }

    public function testTheBestAvailableSource(): void
    {
        // Everybody came from qualification
        self::assertSame(SeatingSource::EarlierRounds, SeatingProposer::defaultSource(100, 100, 95));
        // Most came from qualification, a few were seeded straight into the final
        self::assertSame(SeatingSource::EarlierRounds, SeatingProposer::defaultSource(100, 60, 98));
        // A few played a warm-up round, most have MySpeedPuzzling times
        self::assertSame(SeatingSource::MspTimes, SeatingProposer::defaultSource(100, 10, 80));
        // The first round of an event
        self::assertSame(SeatingSource::MspTimes, SeatingProposer::defaultSource(40, 0, 12));
        // Nothing known about anybody
        self::assertSame(SeatingSource::Name, SeatingProposer::defaultSource(40, 0, 0));
        self::assertSame(SeatingSource::Name, SeatingProposer::defaultSource(0, 0, 0));
    }

    public function testEarlierRoundsSeedEachEntrantByTheirLatestRankedResult(): void
    {
        $groupA = self::overview('group-a', '2026-10-01 09:00', 1000);
        $groupB = self::overview('group-b', '2026-10-01 09:00', 1000);
        $semi = self::overview('semi', '2026-10-01 13:00', 1000);
        $final = self::overview('final', '2026-10-01 17:00', 1000);
        $rounds = [$groupA, $groupB, $semi, $final];

        $entriesByRound = [
            'group-a' => self::ranked('group-a', ['anna' => RoundEntryResult::finished(3600), 'ben' => RoundEntryResult::finished(4000), 'cara' => RoundEntryResult::finished(4500)]),
            'group-b' => self::ranked('group-b', ['gina' => RoundEntryResult::finished(3900), 'hugo' => RoundEntryResult::finished(4100), 'dan' => RoundEntryResult::didNotStart()]),
            // Anna and Gina played the semifinal: that is where they are seeded from; Ben did not start it
            'semi' => self::ranked('semi', ['gina' => RoundEntryResult::finished(3000), 'anna' => RoundEntryResult::finished(3300), 'ben' => RoundEntryResult::didNotStart()]),
        ];

        self::assertSame(['group-a', 'group-b', 'semi'], array_map(
            static fn (RoundResultsOverview $round): string => $round->roundId,
            SeatingProposer::earlierRounds($rounds, $final),
        ));

        $finalEntries = [self::person('anna', 'anna'), self::person('ben', 'ben'), self::person('gina', 'gina'), self::person('hugo', 'hugo'), self::person('dan', 'dan'), self::person('zed', 'zed')];
        $seeds = SeatingProposer::earlierRoundSeeds($rounds, $finalEntries, $entriesByRound);

        // Gina and Anna by the semifinal; Ben did not start it - his group counts; Dan has no ranked result anywhere.
        // The semifinal's winner first, then the seconds by their result ÷ their round's winner:
        // Hugo 4100 / 3900 = 1.05, Anna 3300 / 3000 = 1.10, Ben 4000 / 3600 = 1.11
        self::assertSame([
            self::ref('gina') => ['seed' => 1, 'roundId' => 'semi', 'roundName' => 'semi', 'rank' => 1],
            self::ref('hugo') => ['seed' => 2, 'roundId' => 'group-b', 'roundName' => 'group-b', 'rank' => 2],
            self::ref('anna') => ['seed' => 3, 'roundId' => 'semi', 'roundName' => 'semi', 'rank' => 2],
            self::ref('ben') => ['seed' => 4, 'roundId' => 'group-a', 'roundName' => 'group-a', 'rank' => 2],
        ], $seeds);
    }

    public function testAPairIsFoundInEarlierRoundsOnlyAsExactlyTheSamePeople(): void
    {
        $pairs = self::overview('pairs', '2026-10-01 09:00', 500);
        $pairsFinal = self::overview('pairs-final', '2026-10-01 15:00', 500);
        /** @return list<RoundResultEntryMember> */
        $people = static fn (string ...$ids): array => array_values(array_map(static fn (string $id): RoundResultEntryMember => self::member($id), $ids));

        $entriesByRound = ['pairs' => self::rankedTeams('pairs', [
            'sharks' => [$people('anna', 'ben'), RoundEntryResult::finished(2000)],
            'edges' => [$people('cara', 'dan'), RoundEntryResult::finished(2100)],
        ])];

        $finalEntries = [
            self::team('final-sharks', $people('ben', 'anna')),
            // Cara with somebody else is another pair
            self::team('final-mixed', $people('cara', 'eva')),
        ];

        self::assertSame(
            [self::teamRef('final-sharks') => ['seed' => 1, 'roundId' => 'pairs', 'roundName' => 'pairs', 'rank' => 1]],
            SeatingProposer::earlierRoundSeeds([$pairs, $pairsFinal], $finalEntries, $entriesByRound),
        );
    }

    public function testMySpeedPuzzlingTimesOfPeoplePairsAndTeams(): void
    {
        $visible = ['p-anna' => true, 'p-ben' => true, 'p-cara' => true];
        $exactPair = TeamComposition::keyFromMemberKeys(['p-anna', 'p-ben']);

        $entries = [
            self::person('anna', 'Anna', 'p-anna'),
            self::person('dora', 'Dora'),
            // A pair that puzzled together: their own pair time
            self::team('anna-ben', [self::member('anna', 'p-anna'), self::member('ben', 'p-ben')]),
            // Never puzzled together: the mean of the members' times
            self::team('ben-cara', [self::member('ben', 'p-ben'), self::member('cara', 'p-cara')]),
            // A member without a player: the others' times
            self::team('cara-guest', [self::member('cara', 'p-cara'), self::member('guest')]),
            // A member private to the organiser is not visible - only the others count
            self::team('cara-private', [self::member('cara', 'p-cara'), self::member('gina', 'p-gina')]),
            // Nobody with data
            self::team('nobody', [self::member('x'), self::member('y')]),
        ];

        $seconds = SeatingProposer::entrySeconds(
            $entries,
            ['p-anna' => 3000, 'p-ben' => 4000, 'p-cara' => 5001],
            [$exactPair => 1800],
            $visible,
        );

        self::assertSame([
            self::ref('anna') => 3000,
            self::teamRef('anna-ben') => 1800,
            self::teamRef('ben-cara') => 4501,
            self::teamRef('cara-guest') => 5001,
            self::teamRef('cara-private') => 5001,
        ], $seconds);
    }

    public function testOnlyAPairOfVisiblePlayersHasAPuzzlingTeamIdentity(): void
    {
        $visible = ['p-anna' => true, 'p-ben' => true];

        self::assertSame(
            TeamComposition::keyFromMemberKeys(['p-ben', 'p-anna']),
            SeatingProposer::compositionKey(self::team('t', [self::member('anna', 'P-ANNA'), self::member('ben', 'p-ben')]), $visible),
        );
        self::assertNull(SeatingProposer::compositionKey(self::team('t', [self::member('anna', 'p-anna'), self::member('gina', 'p-gina')]), $visible));
        self::assertNull(SeatingProposer::compositionKey(self::team('t', [self::member('anna', 'p-anna'), self::member('guest')]), $visible));
        self::assertNull(SeatingProposer::compositionKey(self::team('t', [self::member('anna', 'p-anna')]), $visible));
        // One account linked to two members is not two people
        self::assertNull(SeatingProposer::compositionKey(self::team('t', [self::member('anna', 'p-anna'), self::member('anna2', 'p-anna')]), $visible));
    }

    /**
     * @param list<RoundResultEntry> $entries
     * @return list<string>
     */
    private static function names(array $entries): array
    {
        return array_map(static fn (RoundResultEntry $entry): string => $entry->displayName(), $entries);
    }

    private static function uuid(string $name): string
    {
        $hash = md5($name);

        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }

    private static function ref(string $name): string
    {
        return RoundEntryRef::participantRound(self::uuid($name))->toString();
    }

    private static function teamRef(string $name): string
    {
        return RoundEntryRef::team(self::uuid($name))->toString();
    }

    private static function person(string $id, string $name, null|string $playerId = null, null|string $roundId = null, null|RoundEntryResult $result = null, null|int $rank = null): RoundResultEntry
    {
        return new RoundResultEntry(
            ref: RoundEntryRef::participantRound(self::uuid($id)),
            kind: RoundResultEntry::KIND_PERSON,
            roundId: $roundId ?? self::ROUND,
            name: $name,
            participantId: self::uuid('participant-' . $id),
            country: null,
            countries: [],
            members: [],
            playerId: $playerId,
            playerCode: null,
            playerName: null,
            tableNumber: null,
            result: $result ?? RoundEntryResult::none(),
            rank: $rank,
            qualifiedAt: null,
            resultEnteredAt: null,
            resultEnteredById: null,
            resultEnteredByName: null,
        );
    }

    private static function member(string $id, null|string $playerId = null): RoundResultEntryMember
    {
        return new RoundResultEntryMember(
            participantId: self::uuid('participant-' . $id),
            participantRoundId: self::uuid('member-round-' . $id),
            name: $id,
            country: null,
            playerId: $playerId,
            playerCode: null,
            playerName: null,
        );
    }

    /**
     * @param list<RoundResultEntryMember> $members
     */
    private static function team(string $id, array $members, null|string $roundId = null, null|RoundEntryResult $result = null, null|int $rank = null): RoundResultEntry
    {
        return new RoundResultEntry(
            ref: RoundEntryRef::team(self::uuid($id)),
            kind: RoundResultEntry::KIND_TEAM,
            roundId: $roundId ?? self::ROUND,
            name: $id,
            participantId: null,
            country: null,
            countries: [],
            members: $members,
            playerId: null,
            playerCode: null,
            playerName: null,
            tableNumber: null,
            result: $result ?? RoundEntryResult::none(),
            rank: $rank,
            qualifiedAt: null,
            resultEnteredAt: null,
            resultEnteredById: null,
            resultEnteredByName: null,
        );
    }

    /**
     * Entries of an earlier round with their ranks, as GetRoundResultEntries answers them - the people share
     * participant ids with the later round's entries of the same name.
     *
     * @param array<string, RoundEntryResult> $results name => result
     * @return list<RoundResultEntry>
     */
    private static function ranked(string $roundId, array $results): array
    {
        $ranks = OfficialResultsRanking::rank($results);
        $entries = [];

        foreach ($results as $name => $result) {
            $entries[] = self::person($roundId . '-' . $name, $name, null, $roundId, $result, $ranks[$name]);
        }

        return array_map(static fn (RoundResultEntry $entry): RoundResultEntry => new RoundResultEntry(
            ref: $entry->ref,
            kind: $entry->kind,
            roundId: $entry->roundId,
            name: $entry->name,
            participantId: self::uuid('participant-' . $entry->name),
            country: null,
            countries: [],
            members: [],
            playerId: null,
            playerCode: null,
            playerName: null,
            tableNumber: null,
            result: $entry->result,
            rank: $entry->rank,
            qualifiedAt: null,
            resultEnteredAt: null,
            resultEnteredById: null,
            resultEnteredByName: null,
        ), $entries);
    }

    /**
     * @param array<string, array{list<RoundResultEntryMember>, RoundEntryResult}> $teams
     * @return list<RoundResultEntry>
     */
    private static function rankedTeams(string $roundId, array $teams): array
    {
        $ranks = OfficialResultsRanking::rank(array_map(static fn (array $team): RoundEntryResult => $team[1], $teams));
        $entries = [];

        foreach ($teams as $name => [$members, $result]) {
            $entries[] = self::team($roundId . '-' . $name, $members, $roundId, $result, $ranks[$name]);
        }

        return $entries;
    }

    private static function overview(string $id, string $startsAt, null|int $piecesCount): RoundResultsOverview
    {
        return new RoundResultsOverview(
            roundId: $id,
            name: $id,
            category: str_starts_with($id, 'pairs') ? 'duo' : 'solo',
            startsAt: new DateTimeImmutable($startsAt),
            minutesLimit: 90,
            slug: $id,
            stopwatchStatus: null,
            stopwatchStartedAt: null,
            stopwatchStoppedAt: null,
            resultsPublishedAt: null,
            resultsFirstPublishedAt: null,
            tableNumbersOff: false,
            puzzlesCount: 1,
            piecesCount: $piecesCount,
            entriesTotal: 0,
            entriesWithTableNumber: 0,
            entriesWithResult: 0,
            entriesQualified: 0,
        );
    }
}
