<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Tests\TestClock;

final class AffiliateTest extends TestCase
{
    public function testNewPlayerIsNotInReferralProgram(): void
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'test1',
            userId: null,
            name: null,
            registeredAt: TestClock::now(),
        );

        self::assertFalse($player->isInReferralProgram());
        self::assertNull($player->referralProgramJoinedAt);
    }

    public function testJoinReferralProgram(): void
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'test1',
            userId: null,
            name: null,
            registeredAt: TestClock::now(),
        );

        $now = TestClock::now();
        $player->joinReferralProgram($now);

        self::assertTrue($player->isInReferralProgram());
        self::assertSame($now, $player->referralProgramJoinedAt);
        self::assertFalse($player->referralProgramSuspended);
    }

    public function testSuspendFromReferralProgram(): void
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'test1',
            userId: null,
            name: null,
            registeredAt: TestClock::now(),
        );

        $player->joinReferralProgram(TestClock::now());
        $player->suspendFromReferralProgram();

        self::assertFalse($player->isInReferralProgram());
        self::assertTrue($player->referralProgramSuspended);
        self::assertNotNull($player->referralProgramJoinedAt);
    }

    public function testUnsuspendFromReferralProgram(): void
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'test1',
            userId: null,
            name: null,
            registeredAt: TestClock::now(),
        );

        $player->joinReferralProgram(TestClock::now());
        $player->suspendFromReferralProgram();
        $player->unsuspendFromReferralProgram();

        self::assertTrue($player->isInReferralProgram());
        self::assertFalse($player->referralProgramSuspended);
    }
}
