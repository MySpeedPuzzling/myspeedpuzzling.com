<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PrintRoundTablesControllerTest extends WebTestCase
{
    public function testPrintPageRendersForMaintainer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/print-round-tables/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseIsSuccessful();
    }
}
