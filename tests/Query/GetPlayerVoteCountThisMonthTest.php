<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerVoteCountThisMonth;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerVoteCountThisMonthTest extends KernelTestCase
{
    private GetPlayerVoteCountThisMonth $getPlayerVoteCountThisMonth;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getPlayerVoteCountThisMonth = self::getContainer()->get(GetPlayerVoteCountThisMonth::class);
    }

    public function testCountsVotesThisMonth(): void
    {
        // PLAYER_ADMIN voted 2 days ago (this month) - should count
        // Also has auto-vote on new request 1 day ago
        $count = ($this->getPlayerVoteCountThisMonth)(PlayerFixture::PLAYER_ADMIN);
        self::assertGreaterThanOrEqual(1, $count);
    }

    public function testOldVotesDontCount(): void
    {
        // PLAYER_REGULAR voted 35 days ago (last month) - should not count
        $count = ($this->getPlayerVoteCountThisMonth)(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(0, $count);
    }
}
