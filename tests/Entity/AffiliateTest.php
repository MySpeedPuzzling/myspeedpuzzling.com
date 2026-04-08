<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;

final class AffiliateTest extends TestCase
{
    public function testNewPlayerIsNotInReferralProgram(): void
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'test1',
            userId: null,
            email: null,
            name: null,
            registeredAt: new DateTimeImmutable(),
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
            email: null,
            name: null,
            registeredAt: new DateTimeImmutable(),
        );

        $now = new DateTimeImmutable();
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
            email: null,
            name: null,
            registeredAt: new DateTimeImmutable(),
        );

        $player->joinReferralProgram(new DateTimeImmutable());
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
            email: null,
            name: null,
            registeredAt: new DateTimeImmutable(),
        );

        $player->joinReferralProgram(new DateTimeImmutable());
        $player->suspendFromReferralProgram();
        $player->unsuspendFromReferralProgram();

        self::assertTrue($player->isInReferralProgram());
        self::assertFalse($player->referralProgramSuspended);
    }
}
