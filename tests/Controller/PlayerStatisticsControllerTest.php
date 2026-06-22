<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayerStatisticsControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testNonsensicalYearRedirectsToCanonicalUrl(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?month=6&year=783');

        $this->assertResponseRedirects('/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);
    }

    public function testMonthWithoutYearRedirectsToCanonicalUrl(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?month=6');

        $this->assertResponseRedirects('/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR);
    }

    public function testValidPeriodIsNotRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-statistics/' . PlayerFixture::PLAYER_REGULAR . '?month=6&year=2024');

        $this->assertResponseIsSuccessful();
    }
}
