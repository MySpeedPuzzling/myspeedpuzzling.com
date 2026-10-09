<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\FollowTargetKind;

final class FollowTargetTest extends TestCase
{
    private const string ID = '018d0042-0000-0000-0000-000000000001';

    public function testAnOrganizationIsAThirdTarget(): void
    {
        $target = FollowTarget::organization(strtoupper(self::ID));

        self::assertSame(FollowTargetKind::Organization, $target->kind);
        self::assertSame(self::ID, $target->id);
        self::assertTrue($target->isOrganization());
        self::assertFalse($target->isSeries());
        self::assertSame('organization:' . self::ID, $target->toString());
    }

    public function testTheStringFormRoundTripsForEveryKind(): void
    {
        foreach ([FollowTarget::competition(self::ID), FollowTarget::series(self::ID), FollowTarget::organization(self::ID)] as $target) {
            $parsed = FollowTarget::tryFromString($target->toString());

            self::assertNotNull($parsed);
            self::assertSame($target->kind, $parsed->kind);
            self::assertSame($target->id, $parsed->id);
        }
    }

    public function testParsingIsCaseInsensitiveAndTrimmed(): void
    {
        $target = FollowTarget::tryFromString('  Organization:' . strtoupper(self::ID) . ' ');

        self::assertNotNull($target);
        self::assertTrue($target->isOrganization());
        self::assertSame(self::ID, $target->id);

        $series = FollowTarget::tryFromString('series:' . self::ID);
        self::assertNotNull($series);
        self::assertTrue($series->isSeries());
        self::assertFalse($series->isOrganization());

        $competition = FollowTarget::tryFromString('competition:' . self::ID);
        self::assertNotNull($competition);
        self::assertSame(FollowTargetKind::Competition, $competition->kind);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'no kind' => [self::ID];
        yield 'unknown kind' => ['player:' . self::ID];
        yield 'not a uuid' => ['organization:riverbend'];
        yield 'missing id' => ['organization:'];
        yield 'edition is not a kind' => ['edition:' . self::ID];
    }

    #[DataProvider('invalidStrings')]
    public function testInvalidStringsAreNoTarget(string $value): void
    {
        self::assertNull(FollowTarget::tryFromString($value));
    }
}
