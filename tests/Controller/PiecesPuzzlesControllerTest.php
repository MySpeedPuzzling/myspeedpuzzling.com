<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PiecesPuzzlesControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPiecesHub(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/1000-pieces');

        $this->assertResponseIsSuccessful();

        self::assertStringContainsString('1000 Piece Puzzles', (string) $crawler->filter('title')->text());
        self::assertStringContainsString('1000 Piece Puzzles', (string) $crawler->filter('h1')->text());
    }

    public function testLoggedInUserCanAccessPiecesHub(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle/500-pieces');

        $this->assertResponseIsSuccessful();
    }

    public function testPiecesHubShowsPuzzleGrid(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/1000-pieces');

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.puzzle-list-item')->count());
    }

    public function testDisallowedPiecesValueReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/123-pieces');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLocalizedRouteWorks(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/puzzle/1000-dilku');

        $this->assertResponseIsSuccessful();
    }

    public function testPuzzleDetailRouteIsNotShadowed(): void
    {
        $browser = self::createClient();

        // Regression guard: the higher-priority pieces route must not swallow
        // /en/puzzle/{puzzleId} URLs.
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
    }
}
