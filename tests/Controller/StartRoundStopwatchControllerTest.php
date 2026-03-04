<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StartRoundStopwatchControllerTest extends WebTestCase
{
    public function testStartRedirects(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/start-round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseRedirects();
    }

    public function testAnonymousCannotStart(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/start-round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseRedirects();
    }
}
