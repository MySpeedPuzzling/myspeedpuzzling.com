<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LadderControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/ladder');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/ladder');

        $this->assertResponseIsSuccessful();
    }

    public function testHeadingHoldsOnlyTheTitleWithTheCategoryMenuNextToIt(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/ladder');

        $this->assertResponseIsSuccessful();

        $heading = $crawler->filter('h1');
        self::assertCount(1, $heading);
        self::assertSame('Speed Puzzling Leaderboard', trim($heading->text()));
        self::assertCount(0, $heading->filter('.dropdown, a, button'));

        // The menu is still there, right after the heading, and names the page it is on
        $menuButton = $crawler->filter('h1 + .dropdown > button');
        self::assertCount(1, $menuButton);
        self::assertSame('Overview', trim($menuButton->text()));
    }
}
