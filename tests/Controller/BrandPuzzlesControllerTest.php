<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BrandPuzzlesControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessBrandHub(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();

        self::assertStringContainsString('Ravensburger Puzzles', (string) $crawler->filter('title')->text());
        self::assertStringContainsString('Ravensburger Puzzles', (string) $crawler->filter('h1')->text());
    }

    public function testLoggedInUserCanAccessBrandHub(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();
    }

    public function testBrandWithEnoughDataIsIndexable(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testThinBrandRendersWithNoindex(): void
    {
        $browser = self::createClient();

        // "Unknown Brand" has a single (unapproved) puzzle and no recorded solves
        $crawler = $browser->request('GET', '/en/puzzle/brand/unknown-brand');

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testBrandHubShowsPuzzleGrid(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.puzzle-list-item')->count());
    }

    public function testUnknownSlugReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/brand/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLocalizedRouteWorks(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/puzzle/znacka/ravensburger');

        $this->assertResponseIsSuccessful();
    }
}
