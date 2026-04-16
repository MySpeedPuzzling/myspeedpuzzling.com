<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ActivityCalendarControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPublicPlayerCalendar(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
    }

    public function testAnonymousUserIsRedirectedForPrivatePlayer(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertResponseRedirects();
    }

    public function testLoggedInVisitorIsRedirectedForPrivatePlayer(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertResponseRedirects();
    }

    public function testOwnerCanAccessTheirOwnPrivateCalendar(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_PRIVATE);

        self::assertResponseIsSuccessful();
    }
}
