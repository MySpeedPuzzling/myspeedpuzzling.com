<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DifficultyRankingSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DifficultyTier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BrandDifficultyRankingControllerTest extends WebTestCase
{
    use DifficultyRankingSeeding;
    use DifficultyRankingPageAssertions;

    private const string TREFL = ManufacturerFixture::MANUFACTURER_TREFL;

    public function testUnknownBrandReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/brand/does-not-exist/hardest');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testBrandWithTooFewRatedPuzzlesReturns404(): void
    {
        $browser = self::createClient();

        $this->seedRatedPuzzles($browser->getContainer(), self::TREFL, 500, 39, 'Trefl');

        $browser->request('GET', '/en/puzzle/brand/trefl/hardest');
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/puzzle/brand/trefl/easiest');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnapprovedBrandNeverGetsAList(): void
    {
        $browser = self::createClient();

        $this->seedRatedPuzzles($browser->getContainer(), ManufacturerFixture::MANUFACTURER_UNAPPROVED, 1000, 45, 'Unapproved brand');

        $browser->request('GET', '/en/puzzle/brand/unknown-brand/hardest');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnonymousVisitorSeesTheTwentyFiveHardestNamesAcrossPieceCounts(): void
    {
        $browser = self::createClient();
        $ids = $this->seedBrand($browser);

        $crawler = $browser->request('GET', '/en/puzzle/brand/trefl/hardest');

        $this->assertResponseIsSuccessful();
        self::assertSame('25 Hardest Trefl Puzzles (Real Data) – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertSame('The 25 Hardest Trefl Puzzles', $crawler->filter('h1')->text());
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('http://localhost/en/puzzle/brand/trefl/hardest', $crawler->filter('link[rel="canonical"]')->attr('href'));

        // All 20 small ones are harder than the large ones - the ranking mixes piece counts
        $expected = [
            ...array_map(static fn (int $i): string => sprintf('Trefl small %03d', $i), range(1, 20)),
            ...array_map(static fn (int $i): string => sprintf('Trefl large %03d', $i), range(1, 5)),
        ];
        self::assertSame($expected, $this->rankedNames($crawler));

        // A brand list shows each puzzle's piece count (the heading already names the brand)
        $rows = $crawler->filter('#difficulty-ranking > li');
        self::assertStringContainsString("500\u{a0}pieces", $rows->first()->text());
        self::assertStringContainsString("1000\u{a0}pieces", $rows->eq(20)->text());

        self::assertCount(1, $crawler->filter('main a[href="/en/puzzle/brand/trefl/easiest"]'));
        self::assertGreaterThan(0, $crawler->filter('main a[href="/en/puzzle/brand/trefl"]')->count());
        self::assertStringContainsString('Full ranking of 40 puzzles with difficulty scores', $crawler->filter('main')->text());

        $itemList = $this->jsonLd($crawler, 'ItemList');
        self::assertSame(25, $itemList['numberOfItems']);
        self::assertIsArray($itemList['itemListElement']);
        self::assertSame([
            '@type' => 'ListItem',
            'position' => 1,
            'url' => 'http://localhost/en/puzzle/' . $ids[0],
            'name' => 'Trefl Trefl small 001',
        ], $itemList['itemListElement'][0]);

        $breadcrumb = $this->jsonLd($crawler, 'BreadcrumbList');
        self::assertIsArray($breadcrumb['itemListElement']);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Jigsaw Puzzle Database', 'item' => 'http://localhost/en/puzzle'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Trefl', 'item' => 'http://localhost/en/puzzle/brand/trefl'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'The 25 Hardest Trefl Puzzles'],
        ], $breadcrumb['itemListElement']);
    }

    public function testEasiestListStartsWithTheLowestScore(): void
    {
        $browser = self::createClient();
        $this->seedBrand($browser);

        $crawler = $browser->request('GET', '/en/puzzle/brand/trefl/easiest');

        $this->assertResponseIsSuccessful();
        self::assertSame('The 25 Easiest Trefl Puzzles', $crawler->filter('h1')->text());
        self::assertSame('Trefl large 020', $this->rankedNames($crawler)[0]);
    }

    #[DataProvider('nonMemberViewers')]
    public function testPublicHtmlNeverContainsDifficulty(null|string $playerId): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $this->seedBrand($browser);

        foreach (['/en/puzzle/brand/trefl/hardest', '/en/puzzle/brand/trefl/easiest'] as $url) {
            $crawler = $browser->request('GET', $url);
            $this->assertResponseIsSuccessful();

            $this->assertNoDifficultyIn($crawler, (string) $browser->getResponse()->getContent(), $this->seededBrandScores(), 'en');
        }
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function nonMemberViewers(): iterable
    {
        yield 'anonymous' => [null];
        yield 'signed in without membership' => [PlayerFixture::PLAYER_REGULAR];
    }

    public function testMemberSeesDifficulty(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->seedBrand($browser);

        $crawler = $browser->request('GET', '/en/puzzle/brand/trefl/hardest');

        $this->assertResponseIsSuccessful();
        self::assertSame('The 40 Hardest Trefl Puzzles', $crawler->filter('h1')->text());
        self::assertCount(40, $crawler->filter('#difficulty-ranking > li'));

        $main = $crawler->filter('main')->text();
        self::assertStringContainsString($this->translateTo(DifficultyTier::VeryHard->translationKey()), $main);
        self::assertStringContainsString('91% harder than average', $main);
        self::assertStringContainsString('All 40 rated puzzles', $main);
        self::assertStringNotContainsString('Full ranking of', $main);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localizedUrls(): iterable
    {
        yield 'cs hardest' => ['/puzzle/znacka/trefl/nejtezsi', '25 nejtěžších puzzlí Trefl'];
        yield 'cs easiest' => ['/puzzle/znacka/trefl/nejlehci', '25 nejlehčích puzzlí Trefl'];
        yield 'de hardest' => ['/de/puzzle/marke/trefl/schwierigste', 'Die 25 schwierigsten Trefl Puzzles'];
        yield 'de easiest' => ['/de/puzzle/marke/trefl/leichteste', 'Die 25 leichtesten Trefl Puzzles'];
        yield 'fr hardest' => ['/fr/puzzle/marque/trefl/plus-difficiles', 'Les 25 puzzles Trefl les plus difficiles'];
        yield 'fr easiest' => ['/fr/puzzle/marque/trefl/plus-faciles', 'Les 25 puzzles Trefl les plus faciles'];
        yield 'es hardest' => ['/es/puzzles/marca/trefl/mas-dificiles', 'Los 25 puzzles Trefl más difíciles'];
        yield 'es easiest' => ['/es/puzzles/marca/trefl/mas-faciles', 'Los 25 puzzles Trefl más fáciles'];
        yield 'ja hardest' => ['/ja/' . rawurlencode('パズル') . '/' . rawurlencode('ブランド') . '/trefl/' . rawurlencode('難しい'), 'Treflの難しいパズル TOP25'];
        yield 'ja easiest' => ['/ja/' . rawurlencode('パズル') . '/' . rawurlencode('ブランド') . '/trefl/' . rawurlencode('簡単'), 'Treflの簡単なパズル TOP25'];
    }

    #[DataProvider('localizedUrls')]
    public function testLocalizedUrl(string $url, string $expectedHeading): void
    {
        $browser = self::createClient();
        $this->seedBrand($browser);

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        self::assertSame($expectedHeading, $crawler->filter('h1')->text());
        self::assertCount(25, $crawler->filter('#difficulty-ranking > li'));
    }

    /**
     * 40 rated Trefl puzzles: 20 of 500 pieces ("Trefl small", the harder ones)
     * and 20 of 1000 pieces ("Trefl large").
     *
     * @return list<string> puzzle ids, hardest first
     */
    private function seedBrand(KernelBrowser $browser): array
    {
        $container = $browser->getContainer();

        return [
            ...$this->seedRatedPuzzles($container, self::TREFL, 500, 20, 'Trefl small'),
            ...$this->seedRatedPuzzles($container, self::TREFL, 1000, 20, 'Trefl large', highestScore: 1.9137 - 20 * 0.0113),
        ];
    }

    /**
     * @return list<float>
     */
    private function seededBrandScores(): array
    {
        return array_map(static fn (int $i): float => round(1.9137 - $i * 0.0113, 4), range(0, 39));
    }
}
