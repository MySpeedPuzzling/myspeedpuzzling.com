<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OfficialResults;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\UnreadableRoundResultChanges;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Value\NewRoundEntry;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultField;

final class RoundResultChangesParserTest extends TestCase
{
    private const string CHANGE = '018d0099-0000-0000-0000-000000000001';
    private const string ENTRY = '018d0099-0000-0000-0000-0000000000AA';
    private const string PARTICIPANT = '018d0099-0000-0000-0000-0000000000bb';

    public function testReadsEveryField(): void
    {
        $changes = RoundResultChangesParser::parse([
            ['clientChangeId' => self::CHANGE, 'entry' => 'participant_round:' . self::ENTRY, 'field' => 'result', 'from' => null, 'to' => ['seconds' => 5025]],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000002', 'entry' => 'team:' . self::ENTRY, 'field' => 'table_number', 'from' => 3, 'to' => null],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000003', 'entry' => 'team:' . self::ENTRY, 'field' => 'qualified', 'from' => false, 'to' => true],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000004', 'entry' => 'team:' . self::ENTRY, 'field' => 'result', 'from' => ['piecesPlaced' => 479], 'to' => ['didNotStart' => true]],
        ]);

        self::assertCount(4, $changes);
        self::assertSame(RoundResultField::Result, $changes[0]->field);
        self::assertSame('participant_round:' . strtolower(self::ENTRY), $changes[0]->entryRef()?->toString());
        self::assertInstanceOf(RoundEntryResult::class, $changes[0]->to);
        self::assertSame(5025, $changes[0]->to->seconds);
        self::assertTrue($changes[0]->from instanceof RoundEntryResult && $changes[0]->from->isNone());
        self::assertSame(3, $changes[1]->from);
        self::assertNull($changes[1]->to);
        self::assertTrue($changes[2]->to);
        self::assertInstanceOf(RoundEntryResult::class, $changes[3]->to);
        self::assertTrue($changes[3]->to->didNotStart);
        self::assertNull($changes[0]->rejectedReason);
    }

    public function testReadsANewEntrant(): void
    {
        $changes = RoundResultChangesParser::parse([[
            'clientChangeId' => self::CHANGE,
            'newEntry' => [
                'clientEntryId' => self::ENTRY,
                'kind' => 'team',
                'name' => '  Puzzle   Sharks ',
                'members' => ['Jo  Doe', ['name' => 'Kim Example', 'country' => 'DE'], ['participantId' => strtoupper(self::PARTICIPANT), 'name' => 'Lee Known']],
            ],
            'field' => 'table_number',
            'from' => null,
            'to' => 12,
        ]]);

        $newEntry = $changes[0]->newEntry;
        self::assertInstanceOf(NewRoundEntry::class, $newEntry);
        self::assertSame(NewRoundEntry::KIND_TEAM, $newEntry->kind);
        self::assertSame('Puzzle Sharks', $newEntry->name);
        // A participant of the event comes by id - the name sent along is only the device's label
        self::assertSame([
            ['name' => 'Jo Doe', 'country' => null, 'participantId' => null],
            ['name' => 'Kim Example', 'country' => 'de', 'participantId' => null],
            ['name' => null, 'country' => null, 'participantId' => self::PARTICIPANT],
        ], $newEntry->members);
        self::assertSame([self::PARTICIPANT], $newEntry->existingParticipantIds());
        self::assertSame('team:' . strtolower(self::ENTRY), $changes[0]->entryRef()?->toString());
    }

    public function testReadsAPersonOfTheEventPutIntoTheRound(): void
    {
        $changes = RoundResultChangesParser::parse([
            ['clientChangeId' => self::CHANGE, 'newEntry' => ['clientEntryId' => self::ENTRY, 'kind' => 'person', 'participantId' => self::PARTICIPANT], 'field' => 'qualified', 'from' => false, 'to' => true],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000002', 'newEntry' => ['clientEntryId' => self::ENTRY, 'kind' => 'person', 'participantId' => 'nope'], 'field' => 'qualified', 'from' => false, 'to' => true],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000003', 'newEntry' => ['clientEntryId' => self::ENTRY, 'kind' => 'team', 'participantId' => self::PARTICIPANT], 'field' => 'qualified', 'from' => false, 'to' => true],
        ]);

        self::assertSame(self::PARTICIPANT, $changes[0]->newEntry?->participantId);
        self::assertNull($changes[0]->newEntry->name);
        self::assertSame([null, 'invalid_change', 'invalid_change'], array_map(static fn ($change): null|string => $change->rejectedReason, $changes));
    }

    public function testAChangeItCannotReadIsRefusedAlone(): void
    {
        $changes = RoundResultChangesParser::parse([
            ['clientChangeId' => self::CHANGE, 'entry' => 'participant_round:' . self::ENTRY, 'field' => 'result', 'from' => null, 'to' => ['seconds' => '5025']],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000002', 'entry' => 'nonsense', 'field' => 'qualified', 'from' => false, 'to' => true],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000003', 'entry' => 'team:' . self::ENTRY, 'field' => 'colour', 'from' => 1, 'to' => 2],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000004', 'entry' => 'team:' . self::ENTRY, 'field' => 'qualified', 'to' => true],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000005', 'newEntry' => ['clientEntryId' => self::ENTRY, 'kind' => 'person', 'name' => 'Jo', 'country' => 'narnia'], 'field' => 'qualified', 'from' => false, 'to' => true],
            ['clientChangeId' => '018d0099-0000-0000-0000-000000000006', 'entry' => 'team:' . self::ENTRY, 'field' => 'result', 'from' => null, 'to' => ['seconds' => 1, 'piecesPlaced' => 2]],
        ]);

        self::assertSame(
            ['invalid_change', 'invalid_change', 'invalid_change', 'invalid_change', 'invalid_change', 'invalid_change'],
            array_map(static fn ($change): null|string => $change->rejectedReason, $changes),
        );
    }

    public function testARequestWithoutChangeIdsIsUnreadable(): void
    {
        self::assertSame('changes_unreadable', self::reasonOf([['entry' => 'team:' . self::ENTRY, 'field' => 'qualified', 'from' => false, 'to' => true]]));
    }

    public function testAChangeIdUsedTwiceIsUnreadable(): void
    {
        $change = ['clientChangeId' => self::CHANGE, 'entry' => 'team:' . self::ENTRY, 'field' => 'qualified', 'from' => false, 'to' => true];

        self::assertSame('change_sent_twice', self::reasonOf([$change, $change]));
    }

    public function testChangesAreAList(): void
    {
        self::assertSame('changes_unreadable', self::reasonOf(['a' => 1]));
    }

    public function testTooManyChangesAreUnreadable(): void
    {
        $changes = [];
        for ($number = 1; $number <= RoundResultChangesParser::MAX_CHANGES + 1; $number++) {
            $changes[] = ['clientChangeId' => sprintf('018d0099-0000-0000-0000-%012d', $number), 'entry' => 'team:' . self::ENTRY, 'field' => 'qualified', 'from' => false, 'to' => true];
        }

        self::assertSame('too_many_changes', self::reasonOf($changes));
    }

    /**
     * The reason key a whole request is refused with - a translated text for the referee (official_results.reason.*).
     */
    private static function reasonOf(mixed $changes): string
    {
        try {
            RoundResultChangesParser::parse($changes);
        } catch (UnreadableRoundResultChanges $exception) {
            return $exception->reason;
        }

        self::fail('The request was read.');
    }
}
