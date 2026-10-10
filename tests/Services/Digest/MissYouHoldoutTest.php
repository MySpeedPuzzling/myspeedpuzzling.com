<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Digest;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\Digest\MissYouHoldout;

final class MissYouHoldoutTest extends TestCase
{
    public function testAPlayerIsAlwaysOnTheSameSide(): void
    {
        $holdout = new MissYouHoldout();
        $playerId = '018d0000-0000-0000-0000-000000000001';

        self::assertSame($holdout->isHeldOut($playerId), (new MissYouHoldout())->isHeldOut($playerId));
        self::assertSame($holdout->isHeldOut($playerId), $holdout->isHeldOut(strtoupper($playerId)));
    }

    public function testAboutTenPercentAreHeldOut(): void
    {
        $holdout = new MissYouHoldout();
        $heldOut = 0;

        for ($i = 0; $i < 10_000; $i++) {
            $heldOut += $holdout->isHeldOut(Uuid::uuid7()->toString()) ? 1 : 0;
        }

        self::assertGreaterThan(850, $heldOut);
        self::assertLessThan(1150, $heldOut);
    }

    public function testTheShareIsTheConstructorsOne(): void
    {
        $playerId = Uuid::uuid7()->toString();

        self::assertFalse((new MissYouHoldout(0))->isHeldOut($playerId));
        self::assertTrue((new MissYouHoldout(100))->isHeldOut($playerId));
    }
}
