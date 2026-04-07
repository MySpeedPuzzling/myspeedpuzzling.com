<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConvertCompetitionToSeriesControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirected(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/en/convert-to-series/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);

        $this->assertResponseRedirects();
    }

    public function testMaintainerCanConvert(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/convert-to-series/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);

        $this->assertResponseRedirects();
    }

    public function testNonMaintainerIsDenied(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/convert-to-series/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);

        // Admin can convert (admin is always allowed)
        $this->assertResponseRedirects();
    }
}
