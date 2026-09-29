<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class LadderPerPiecesControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/ladder/solo/500-pieces');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/ladder/solo/500-pieces');

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, non-empty-string, non-empty-string}>
     */
    public static function provideRecordPages(): iterable
    {
        yield 'solo 1000' => [
            '/en/ladder/solo/1000-pieces',
            'Fastest 1000-Piece Puzzle Times – Solo Records',
            "What's the fastest time to solve a 1000-piece jigsaw puzzle alone?",
        ];

        yield 'solo 500' => [
            '/en/ladder/solo/500-pieces',
            'Fastest 500-Piece Puzzle Times – Solo Records',
            "What's the fastest time to solve a 500-piece jigsaw puzzle alone?",
        ];

        yield 'pairs 1000' => [
            '/en/ladder/pairs/1000-pieces',
            'Fastest 1000-Piece Puzzle Times – Pair Records',
            'How fast can two people solve a 1000-piece jigsaw puzzle?',
        ];

        yield 'teams 500' => [
            '/en/ladder/groups/500-pieces',
            'Fastest 500-Piece Puzzle Times – Team Records',
            'How fast can a team solve a 500-piece jigsaw puzzle?',
        ];

        yield 'solo 1000 in a country' => [
            '/en/ladder/solo/1000-pieces/country/cz',
            'Fastest 1000-Piece Puzzle Times – Solo Records (Czechia)',
            'Czechia: the fastest solo 1000-piece jigsaw puzzle times',
        ];
    }

    /**
     * @param non-empty-string $expectedTitle
     * @param non-empty-string $expectedDescriptionStart
     */
    #[DataProvider('provideRecordPages')]
    public function testRecordPagesAreTitledForTheFastestTimeSearches(string $path, string $expectedTitle, string $expectedDescriptionStart): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path);

        $this->assertResponseIsSuccessful();
        self::assertSame($expectedTitle . ' – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertStringStartsWith($expectedDescriptionStart, $this->metaDescription($crawler));
        self::assertStringStartsWith($expectedTitle, trim($crawler->filter('h1')->text()));
    }

    public function testCountryNameFollowsThePageLanguage(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/de/rangliste/einzelspieler/1000-teile/land/cz');

        $this->assertResponseIsSuccessful();
        self::assertSame('Schnellste Zeiten für 1000-Teile-Puzzles – Solo-Rekorde (Tschechien) – MySpeedPuzzling', $crawler->filter('title')->text());
    }

    public function testCategoryMenuNamesTheCurrentLadder(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/ladder/pairs/1000-pieces');

        $this->assertResponseIsSuccessful();
        // "Pair - 1000&nbsp;pieces", not the "Overview" every ladder used to show
        self::assertSame("Pair - 1000\u{a0}pieces", trim($crawler->filter('.dropdown > button.dropdown-toggle')->first()->text()));
    }

    private function metaDescription(Crawler $crawler): string
    {
        return (string) $crawler->filter('meta[name="description"]')->attr('content');
    }
}
