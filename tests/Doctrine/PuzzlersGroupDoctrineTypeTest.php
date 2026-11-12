<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Doctrine;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

final class PuzzlersGroupDoctrineTypeTest extends TestCase
{
    public function testAGroupWithoutAnonymousSlotsIsStoredExactlyAsBefore(): void
    {
        $stored = (new PuzzlersGroupDoctrineType())->convertToDatabaseValue(new PuzzlersGroup('team-1', [
            new Puzzler('018d0000-0000-0000-0000-0000000000a1', 'Anna', null, null, false),
            new Puzzler(null, 'Eva', null, null, false),
        ]), new PostgreSQLPlatform());

        self::assertSame(
            '{"team_id":"team-1","puzzlers":[{"player_id":"018d0000-0000-0000-0000-0000000000a1","player_name":"Anna"},{"player_id":null,"player_name":"Eva"}]}',
            $stored,
        );
    }

    public function testAnAnonymousSlotSurvivesTheRoundTrip(): void
    {
        $type = new PuzzlersGroupDoctrineType();
        $platform = new PostgreSQLPlatform();

        $stored = $type->convertToDatabaseValue(PuzzlersGroup::anonymous(['a:1', 'a:2']), $platform);

        self::assertSame(
            '{"team_id":null,"puzzlers":[{"player_id":null,"player_name":null,"anonymous_slot":"a:1"},{"player_id":null,"player_name":null,"anonymous_slot":"a:2"}]}',
            $stored,
        );

        $loaded = $type->convertToPHPValue($stored, $platform);

        self::assertInstanceOf(PuzzlersGroup::class, $loaded);
        self::assertSame(['a:1', 'a:2'], array_map(static fn(Puzzler $puzzler) => $puzzler->anonymousSlot, $loaded->puzzlers));
        self::assertSame([null, null], array_map(static fn(Puzzler $puzzler) => $puzzler->playerId, $loaded->puzzlers));
    }
}
