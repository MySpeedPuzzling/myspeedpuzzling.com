<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantsSheet;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\UnreadableSheetChanges;
use SpeedPuzzling\Web\Services\ParticipantsSheet\SheetChangesParser;
use SpeedPuzzling\Web\Value\SheetChangeField;
use SpeedPuzzling\Web\Value\SheetChangeOp;

final class SheetChangesParserTest extends TestCase
{
    private const string ID = '018d0099-0000-0000-0000-00000000000A';
    private const string ID_LOWER = '018d0099-0000-0000-0000-00000000000a';

    public function testEveryOpIsReadWithLowerCasedIds(): void
    {
        $parsed = SheetChangesParser::parse([
            'changesetId' => self::ID,
            'groups' => [[
                'id' => 'row-1',
                'changes' => [
                    ['op' => 'newParticipant', 'id' => self::ID, 'name' => 'Kim Example', 'country' => 'us', 'externalId' => null],
                    ['op' => 'field', 'participant' => self::ID, 'field' => 'externalId', 'from' => null, 'to' => 'R-1'],
                    ['op' => 'player', 'participant' => self::ID, 'from' => null, 'to' => self::ID],
                    ['op' => 'place', 'participant' => self::ID, 'round' => self::ID, 'from' => 'out', 'to' => 'team:' . self::ID],
                    ['op' => 'newTeam', 'id' => self::ID, 'round' => self::ID],
                    ['op' => 'renameTeam', 'team' => self::ID, 'from' => null, 'to' => 'Corners'],
                    ['op' => 'deleteTeam', 'team' => self::ID],
                    ['op' => 'remove', 'participant' => self::ID],
                    ['op' => 'restore', 'participant' => self::ID],
                    ['op' => 'teamSize', 'round' => self::ID, 'from' => null, 'to' => 4],
                ],
            ]],
        ]);

        self::assertSame(self::ID_LOWER, $parsed['changesetId']);
        self::assertFalse($parsed['dryRun']);
        $changes = $parsed['groups'][0]->changes;
        self::assertSame('row-1', $parsed['groups'][0]->id);
        self::assertSame(
            ['newParticipant', 'field', 'player', 'place', 'newTeam', 'renameTeam', 'deleteTeam', 'remove', 'restore', 'teamSize'],
            array_map(static fn ($change): string => $change->op->value, $changes),
        );
        self::assertSame(self::ID_LOWER, $changes[0]->id);
        self::assertSame('Kim Example', $changes[0]->name);
        self::assertSame(SheetChangeField::ExternalId, $changes[1]->field);
        self::assertSame(self::ID_LOWER, $changes[2]->to);
        self::assertSame('team:' . self::ID_LOWER, $changes[3]->to);
        self::assertNull($changes[4]->name);
        self::assertSame(SheetChangeOp::TeamSize, $changes[9]->op);
        self::assertSame(4, $changes[9]->to);
    }

    public function testADryRunNeedsNoChangeSetId(): void
    {
        $parsed = SheetChangesParser::parse(['dryRun' => true, 'groups' => [['id' => 'g', 'changes' => [['op' => 'remove', 'participant' => self::ID]]]]]);

        self::assertTrue($parsed['dryRun']);
        self::assertNull($parsed['changesetId']);
    }

    /**
     * @param array<mixed> $body
     */
    #[DataProvider('unreadable')]
    public function testAnUnreadableChangeSetIsRefusedAsAWhole(array $body, string $reason): void
    {
        try {
            SheetChangesParser::parse($body);
            self::fail('The change set must be refused');
        } catch (UnreadableSheetChanges $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function unreadable(): iterable
    {
        $remove = ['op' => 'remove', 'participant' => self::ID];
        $group = static fn (array ...$changes): array => ['id' => 'g', 'changes' => $changes];

        yield 'no change set id' => [['groups' => [$group($remove)]], 'changeset_id_missing'];
        yield 'a change set id that is no uuid' => [['changesetId' => 'abc', 'groups' => [$group($remove)]], 'changeset_id_missing'];
        yield 'groups not a list' => [['changesetId' => self::ID, 'groups' => ['a' => $group($remove)]], 'not_a_list'];
        yield 'no groups' => [['changesetId' => self::ID, 'groups' => []], 'not_a_list'];
        yield 'changes not a list' => [['changesetId' => self::ID, 'groups' => [['id' => 'g', 'changes' => 'x']]], 'not_a_list'];
        yield 'too many groups' => [['changesetId' => self::ID, 'groups' => array_map(static fn (int $i): array => ['id' => 'g' . $i, 'changes' => [$remove]], range(1, 1001))], 'too_many_groups'];
        yield 'too many changes in a group' => [['changesetId' => self::ID, 'groups' => [['id' => 'g', 'changes' => array_fill(0, 501, $remove)]]], 'too_many_changes'];
        yield 'too many changes in all' => [['changesetId' => self::ID, 'groups' => array_map(static fn (int $i): array => ['id' => 'g' . $i, 'changes' => array_fill(0, 500, $remove)], range(1, 11))], 'too_many_changes'];
        yield 'unknown op' => [['changesetId' => self::ID, 'groups' => [$group(['op' => 'merge'])]], 'unknown_op'];
        yield 'missing participant' => [['changesetId' => self::ID, 'groups' => [$group(['op' => 'remove'])]], 'missing_field'];
        yield 'missing from' => [['changesetId' => self::ID, 'groups' => [$group(['op' => 'field', 'participant' => self::ID, 'field' => 'name', 'to' => 'X'])]], 'missing_field'];
        yield 'unknown field' => [['changesetId' => self::ID, 'groups' => [$group(['op' => 'field', 'participant' => self::ID, 'field' => 'email', 'from' => null, 'to' => 'x'])]], 'missing_field'];
        yield 'a place that is none' => [['changesetId' => self::ID, 'groups' => [$group(['op' => 'place', 'participant' => self::ID, 'round' => self::ID, 'from' => 'out', 'to' => 'maybe'])]], 'missing_field'];
        yield 'a team size that is no number' => [['changesetId' => self::ID, 'groups' => [$group(['op' => 'teamSize', 'round' => self::ID, 'from' => null, 'to' => '4'])]], 'missing_field'];
        yield 'an id that is no uuid' => [['changesetId' => self::ID, 'groups' => [$group(['op' => 'remove', 'participant' => '42'])]], 'invalid_id'];
        yield 'a team place without a uuid' => [['changesetId' => self::ID, 'groups' => [$group(['op' => 'place', 'participant' => self::ID, 'round' => self::ID, 'from' => 'out', 'to' => 'team:42'])]], 'invalid_id'];
        yield 'a group without an id' => [['changesetId' => self::ID, 'groups' => [['changes' => [$remove]]]], 'invalid_id'];
        yield 'a group id too long' => [['changesetId' => self::ID, 'groups' => [['id' => str_repeat('g', 65), 'changes' => [$remove]]]], 'invalid_id'];
        yield 'a group id twice' => [['changesetId' => self::ID, 'groups' => [$group($remove), $group($remove)]], 'duplicate_group_id'];
    }
}
