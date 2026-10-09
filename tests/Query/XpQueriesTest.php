<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetXpEntriesForSolve;
use SpeedPuzzling\Web\Query\GetXpHistory;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Services\Xp\XpRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\XpReason;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class XpQueriesTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        self::getContainer()->get(XpRecomputer::class)->recomputeForPlayer(PlayerFixture::PLAYER_REGULAR);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    public function testXpProfileReflectsLedger(): void
    {
        $query = self::getContainer()->get(GetXpProfile::class);

        $profile = $query->byPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertGreaterThan(0, $profile->xpTotal);
        self::assertGreaterThan(1, $profile->level);
        self::assertFalse($profile->optedOut);
        self::assertFalse($profile->isMaxLevel());
        self::assertNotNull($profile->progressToNext());
        self::assertNotNull($profile->xpToNextLevel());
    }

    public function testXpProfileThrowsForUnknownPlayer(): void
    {
        $query = self::getContainer()->get(GetXpProfile::class);

        $this->expectException(PlayerNotFound::class);

        $query->byPlayerId('00000000-0000-0000-0000-000000000000');
    }

    public function testEntriesForSolveAndTotal(): void
    {
        $query = self::getContainer()->get(GetXpEntriesForSolve::class);

        $lines = $query->forPlayerAndSolvingTime(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_06);

        // The first solve of a 500-piece puzzle: base 5. The fixtures are dated relative to now, so once the
        // full-formula cutoff (XpCalculator::FULL_FORMULA_FROM) is weeks old the weekly boost + daily warm-up
        // lines come along too - the total is whatever the lines add up to
        $amounts = [];
        foreach ($lines as $line) {
            $amounts[$line->reason->value] = $line->amount;
        }

        self::assertSame(5, $amounts[XpReason::SolveBase->value] ?? null);
        self::assertSame(array_sum($amounts), $query->totalForPlayerAndSolvingTime(PlayerFixture::PLAYER_REGULAR, PuzzleSolvingTimeFixture::TIME_06));
        self::assertSame(0, $query->totalForPlayerAndSolvingTime(PlayerFixture::PLAYER_REGULAR, '00000000-0000-0000-0000-000000000000'));
    }

    public function testHistoryPaginates(): void
    {
        $query = self::getContainer()->get(GetXpHistory::class);

        $total = $query->countForPlayer(PlayerFixture::PLAYER_REGULAR);
        self::assertGreaterThan(2, $total);

        $firstPage = $query->forPlayer(PlayerFixture::PLAYER_REGULAR, limit: 2, offset: 0);
        $secondPage = $query->forPlayer(PlayerFixture::PLAYER_REGULAR, limit: 2, offset: 2);

        self::assertCount(2, $firstPage);
        self::assertNotEquals($firstPage, $secondPage);
    }
}
