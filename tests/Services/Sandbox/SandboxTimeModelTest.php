<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Sandbox;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SpeedPuzzling\Web\Services\Sandbox\SandboxTimeModel;

final class SandboxTimeModelTest extends TestCase
{
    public function testAPlayerAndAPuzzleKeepTheirTraits(): void
    {
        self::assertSame(SandboxTimeModel::playerSpeed('a'), SandboxTimeModel::playerSpeed('a'));
        self::assertSame(SandboxTimeModel::puzzleDifficulty('p'), SandboxTimeModel::puzzleDifficulty('p'));
        self::assertSame(SandboxTimeModel::preferredPieces('a'), SandboxTimeModel::preferredPieces('a'));

        for ($i = 0; $i < 200; $i++) {
            $speed = SandboxTimeModel::playerSpeed('player' . $i);
            self::assertGreaterThanOrEqual(0.55, $speed);
            self::assertLessThanOrEqual(1.9, $speed);

            $difficulty = SandboxTimeModel::puzzleDifficulty('puzzle' . $i);
            self::assertGreaterThanOrEqual(0.8, $difficulty);
            self::assertLessThanOrEqual(1.35, $difficulty);
        }
    }

    public function testTimesGrowWithPiecesAndShrinkWithPuzzlers(): void
    {
        self::assertGreaterThan(SandboxTimeModel::baseSeconds(500), SandboxTimeModel::baseSeconds(1000));
        // ~71 minutes for a typical 500
        self::assertEqualsWithDelta(4250, SandboxTimeModel::baseSeconds(500), 1);

        $solo = $this->median(500, 1);
        $pair = $this->median(500, 2);
        $team = $this->median(500, 4);

        self::assertGreaterThan($pair, $solo);
        self::assertGreaterThan($team, $pair);
    }

    private function median(int $pieces, int $puzzlers): int
    {
        $randomizer = new Randomizer(new Mt19937(1));
        $times = [];

        for ($i = 0; $i < 101; $i++) {
            $times[] = SandboxTimeModel::seconds($randomizer, 'player', 'puzzle', $pieces, 1, $puzzlers);
        }

        sort($times);

        return $times[50];
    }
}
