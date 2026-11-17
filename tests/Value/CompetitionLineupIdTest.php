<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\CompetitionLineupId;

/**
 * The line-up = a pair/team for the whole competition, by its exact member set (toolkit B-qr-competitor-page.md §3.2).
 */
final class CompetitionLineupIdTest extends TestCase
{
    private const string EVENT = '018d0020-0000-0000-0000-000000000001';
    private const string OTHER_EVENT = '018d0020-0000-0000-0000-000000000002';
    private const string ANNA = '018d0020-0000-0000-0000-000000000201';
    private const string BEN = '018d0020-0000-0000-0000-000000000202';
    private const string CARA = '018d0020-0000-0000-0000-000000000203';

    public function testTheOrderAndCaseOfTheMembersDoNotMatter(): void
    {
        $id = CompetitionLineupId::of(self::EVENT, [self::ANNA, self::BEN]);

        self::assertSame($id, CompetitionLineupId::of(self::EVENT, [self::BEN, self::ANNA]));
        self::assertSame($id, CompetitionLineupId::of(strtoupper(self::EVENT), [strtoupper(self::BEN), self::ANNA]));
        self::assertTrue(Uuid::isValid($id));
    }

    public function testItIsStable(): void
    {
        // Printed on tags: the formula never changes (uuid5 in the event's namespace over "a,b" of the sorted ids)
        self::assertSame(
            Uuid::uuid5(self::EVENT, self::ANNA . ',' . self::BEN)->toString(),
            CompetitionLineupId::of(self::EVENT, [self::BEN, self::ANNA]),
        );
    }

    public function testAnotherMemberSetOrAnotherEventIsAnotherLineup(): void
    {
        $id = CompetitionLineupId::of(self::EVENT, [self::ANNA, self::BEN]);

        self::assertNotSame($id, CompetitionLineupId::of(self::EVENT, [self::ANNA, self::CARA]));
        self::assertNotSame($id, CompetitionLineupId::of(self::EVENT, [self::ANNA, self::BEN, self::CARA]));
        self::assertNotSame($id, CompetitionLineupId::of(self::EVENT, [self::ANNA]));
        self::assertNotSame($id, CompetitionLineupId::of(self::OTHER_EVENT, [self::ANNA, self::BEN]));
    }
}
