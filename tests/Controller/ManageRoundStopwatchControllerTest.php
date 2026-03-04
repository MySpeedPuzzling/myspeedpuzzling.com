<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManageRoundStopwatchControllerTest extends WebTestCase
{
    public function testAnonymousRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/manage-round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseRedirects();
    }

    public function testMaintainerCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/manage-round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseIsSuccessful();
    }

    public function testNonMaintainerDenied(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/manage-round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        $this->assertResponseStatusCodeSame(403);
    }
}
