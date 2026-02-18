<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
    }

    public function testPuzzleDetailShowsOffersSection(): void
    {
        $browser = self::createClient();

        // PUZZLE_500_01 has SELLSWAP_01 offer in fixtures
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.bi-shop')->count());
    }

    public function testPuzzleDetailHidesOffersSectionWhenNoOffers(): void
    {
        $browser = self::createClient();

        // PUZZLE_1000_04 has no sell/swap items in fixtures
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);

        $this->assertResponseIsSuccessful();
        // The offers card section should not be present (no card with bi-shop icon in card-header)
        self::assertCount(0, $crawler->filter('.card-header .bi-shop'));
    }
}
