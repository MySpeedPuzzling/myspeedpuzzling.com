<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A puzzle's names on its page (docs/features/puzzle-names/README.md "Display" and "SEO"): the main title in the H1,
 * the name in the viewer's language right under it, "Also known as" with every name; the title, the meta description
 * and the structured data name it in the page language only.
 *
 * PUZZLE_1000_02 is Trefl "Puzzle 7", also "Kouzelná zahrada" (cs) and "Zauberhafter Garten" (de).
 */
final class PuzzleDetailNamesTest extends WebTestCase
{
    use ChangesPuzzleRecords;

    public function testCzechPageNamesTheCzechBoxEverywhere(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_1000_02);

        $this->assertResponseIsSuccessful();

        // No " – MySpeedPuzzling": Google shows the site name on its own
        self::assertSame('Trefl Puzzle 7 (Kouzelná zahrada) – puzzle 1000 dílků', $crawler->filter('title')->text());
        self::assertSame('Trefl Puzzle 7 (Kouzelná zahrada) – puzzle 1000 dílků', $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertStringStartsWith('Trefl Puzzle 7 (Kouzelná zahrada) ', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('MySpeedPuzzling', $crawler->filter('meta[property="og:site_name"]')->attr('content'));

        self::assertSame(['Puzzle 7', 'cs', 'Kouzelná zahrada'], self::headNames($crawler));

        // The page language first, then the other tagged names - in Czech
        $expected = ['Kouzelná zahrada · čeština', 'Zauberhafter Garten · němčina'];
        self::assertSame($expected, self::otherNames($crawler->filter('#puzzleDetails')));
        self::assertSame($expected, self::otherNames($crawler->filter('section.puzzle-summary')));
        self::assertSame(['cs', 'de'], $crawler->filter('#puzzleDetails .puzzle-other-name > span[lang]')->each(
            static fn (Crawler $name): string => (string) $name->attr('lang'),
        ));
    }

    public function testCzechPageOfAPuzzleWithoutACzechNameKeepsTheTitleAsItWas(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertSame('Ravensburger Puzzle 1 – puzzle 500 dílků', $crawler->filter('title')->text());
        self::assertStringStartsWith('Ravensburger Puzzle 1 (500 dílků', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertCount(0, $crawler->filter('.puzzle-name-local'));
        self::assertCount(0, $crawler->filter('#puzzleDetails .puzzle-other-name'));
    }

    public function testEnglishPageForAGuestHasNoSecondLine(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02);

        $this->assertResponseIsSuccessful();
        self::assertSame('Trefl Puzzle 7 – 1000 Piece Puzzle', $crawler->filter('title')->text());
        self::assertSame(['Puzzle 7', null, null], self::headNames($crawler));
        self::assertSame("Puzzle 7 1000\u{a0}pieces", $crawler->filter('h1')->text());

        // Every name still, in their stored order
        self::assertSame(
            ['Kouzelná zahrada · Czech', 'Zauberhafter Garten · German'],
            self::otherNames($crawler->filter('section.puzzle-summary')),
        );
    }

    public function testEnglishPageForACzechPlayerShowsTheCzechNameButNotToCrawlers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02);

        $this->assertResponseIsSuccessful();
        self::assertSame(['Puzzle 7', 'cs', 'Kouzelná zahrada'], self::headNames($crawler));
        self::assertSame(
            ['Kouzelná zahrada · Czech', 'Zauberhafter Garten · German'],
            self::otherNames($crawler->filter('#puzzleDetails')),
        );

        // The title follows the page language - what crawlers read is the same for every visitor of one URL
        self::assertSame('Trefl Puzzle 7 – 1000 Piece Puzzle', $crawler->filter('title')->text());
    }

    public function testEnglishPageForAPlayerFromTheUnitedKingdomHasNoSecondLine(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02);

        $this->assertResponseIsSuccessful();
        self::assertSame(['Puzzle 7', null, null], self::headNames($crawler));
    }

    public function testGermanPageListsTheGermanNameFirst(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/de/puzzle/' . PuzzleFixture::PUZZLE_1000_02);

        $this->assertResponseIsSuccessful();
        self::assertSame(['Puzzle 7', 'de', 'Zauberhafter Garten'], self::headNames($crawler));
        self::assertSame(
            ['Zauberhafter Garten · Deutsch', 'Kouzelná zahrada · Tschechisch'],
            self::otherNames($crawler->filter('#puzzleDetails')),
        );
    }

    public function testANameWithoutALanguageSaysSo(): void
    {
        $browser = self::createClient();
        // PUZZLE_300 is also "Kouzelna zahrada", language unknown
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_300);

        $this->assertResponseIsSuccessful();
        self::assertSame(['Kouzelna zahrada · language not set'], self::otherNames($crawler->filter('#puzzleDetails')));
        // lang="" - the language is not known
        self::assertSame('', $crawler->filter('#puzzleDetails .puzzle-other-name > span')->first()->attr('lang'));
        // Never a second line: an untagged name is in no language
        self::assertSame(['Puzzle 11', null, null], self::headNames($crawler));
    }

    public function testAMainTitleNotInEnglishCarriesItsLanguage(): void
    {
        $browser = self::createClient();
        self::changePuzzle(PuzzleFixture::PUZZLE_500_01, static function (Puzzle $puzzle): void {
            $puzzle->changeNames('Pohádkový les', 'cs', $puzzle->alternativeNames(), new DateTimeImmutable());
        });

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertSame('cs', $crawler->filter('h1 .puzzle-head-name')->attr('lang'));

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02);
        self::assertNull($crawler->filter('h1 .puzzle-head-name')->attr('lang'), 'an English main title has no language of its own');
    }

    public function testProductDataNamesEveryNameAndEveryValidBarcode(): void
    {
        $browser = self::createClient();
        // PUZZLE_500_01 has marketplace offers - only then the Product block renders
        self::renamePuzzle(PuzzleFixture::PUZZLE_500_01, 'Puzzle 1', PuzzleNames::fromArray([
            ['name' => 'Bavorská romance', 'language' => 'cs'],
            ['name' => 'Bayerische Romanze', 'language' => 'de'],
        ]));
        // An EAN-13, a UPC-A stored without its leading zero, a wrong check digit, an EAN-8
        self::changePuzzleEan(PuzzleFixture::PUZZLE_500_01, '4005556175895, 36000291452, 4005556147091, 96385074');

        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $product = self::productJsonLd($crawler);
        self::assertSame('Puzzle 1', $product['name']);
        self::assertSame(['Bavorská romance', 'Bayerische Romanze'], $product['alternateName']);
        self::assertSame(['4005556175895', '0036000291452'], $product['gtin13']);
        self::assertSame('96385074', $product['gtin8']);
        self::assertIsString($product['description']);
        self::assertStringStartsWith('Ravensburger Puzzle 1 (Bavorská romance) ', $product['description']);
        self::assertSame('Ravensburger Puzzle 1 (Bavorská romance) – puzzle 500 dílků', $crawler->filter('title')->text());
    }

    public function testProductDataWithoutAValidBarcodeHasNoGtin(): void
    {
        $browser = self::createClient();
        self::changePuzzleEan(PuzzleFixture::PUZZLE_500_01, '4005556147091');

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $product = self::productJsonLd($crawler);
        self::assertArrayNotHasKey('gtin13', $product);
        self::assertArrayNotHasKey('gtin8', $product);
        self::assertArrayNotHasKey('alternateName', $product);
    }

    /**
     * @return array{string, null|string, null|string} The main title, the second line's language and text
     */
    private static function headNames(Crawler $crawler): array
    {
        $local = $crawler->filter('h1 .puzzle-head-local-name');

        return [
            $crawler->filter('h1 .puzzle-head-name')->text(),
            $local->count() > 0 ? $local->attr('lang') : null,
            $local->count() > 0 ? $local->text() : null,
        ];
    }

    /**
     * @return list<string>
     */
    private static function otherNames(Crawler $within): array
    {
        return $within->filter('.puzzle-other-name')->each(static fn (Crawler $name): string => $name->text());
    }

    /**
     * @return array<string, mixed>
     */
    private static function productJsonLd(Crawler $crawler): array
    {
        $product = $crawler->filter('script[type="application/ld+json"]')->reduce(
            static fn (Crawler $script): bool => str_contains($script->text(), '"Product"'),
        );
        self::assertCount(1, $product);

        /** @var array<string, mixed> $data */
        $data = json_decode($product->text(), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
