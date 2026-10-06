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

    public function testMemberPicksTheCountryFromATypeaheadWhoseOptionsOpenTheLadderOfThatCountry(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/ladder/country/cz');

        $this->assertResponseIsSuccessful();

        $select = $crawler->filter('select[data-controller~="country-typeahead"][data-controller~="select-navigate"]');
        self::assertCount(1, $select);

        // All countries first, then the countries with the most players
        $options = $select->filter('option');
        self::assertSame('/en/ladder', $options->eq(0)->attr('value'));
        self::assertSame('All countries', trim($options->eq(0)->text()));
        self::assertSame('/en/ladder/country/cz', $options->eq(1)->attr('value'));
        self::assertSame('fi fi-cz', $options->eq(1)->attr('data-icon'));

        $selected = $select->filter('option[selected]');
        self::assertCount(1, $selected);
        self::assertSame('/en/ladder/country/cz', $selected->attr('value'));
    }
}
