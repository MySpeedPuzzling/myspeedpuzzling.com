<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ComparisonControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/compare-puzzlers/');

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessComparisonPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/compare-puzzlers/');

        $this->assertResponseIsSuccessful();
    }

    public function testAddToComparisonRedirectsToComparison(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/compare-puzzlers/add/' . PlayerFixture::PLAYER_ADMIN . '/');

        $this->assertResponseRedirects();
    }

    public function testPairsModeRenders(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/compare-puzzlers/?mode=pairs');

        $this->assertResponseIsSuccessful();
    }

    public function testFloatingLauncherRendersOnOtherPagesWhenBucketNotEmpty(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Seed the bucket, then visit a non-comparison page: the global launcher must render.
        $browser->request('GET', '/compare-puzzlers/add/' . PlayerFixture::PLAYER_ADMIN . '/');
        $browser->request('GET', '/en/hub');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.comparison-launcher-fab');
    }

    public function testMemberCanRenderChartsView(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Seed a second subject so charts have more than one player to compare.
        $browser->request('GET', '/compare-puzzlers/add/' . PlayerFixture::PLAYER_ADMIN . '/');

        foreach (['wins', 'pieces', 'puzzles', 'difficulty'] as $chart) {
            $browser->request('GET', '/compare-puzzlers/?view=charts&chart=' . $chart);
            $this->assertResponseIsSuccessful();
        }

        // Table view must render the per-puzzle comparison cards (the two players share solo puzzles).
        $crawler = $browser->request('GET', '/compare-puzzlers/');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('.comparison-card')->count());
    }
}
