<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The second name line on every surface that lists puzzles (docs/features/puzzle-names/README.md "Display") and the
 * "Matched: …" line of search results. PUZZLE_1000_02 is Trefl "Puzzle 7", also "Kouzelná zahrada" (cs) and
 * "Zauberhafter Garten" (de); PUZZLE_300 "Puzzle 11" is also "Kouzelna zahrada" in no known language.
 */
final class PuzzleNamesInListsTest extends WebTestCase
{
    use ChangesPuzzleRecords;
    use InteractsWithLiveComponents;

    public function testCatalogueCardsShowTheNameInThePageLanguage(): void
    {
        $client = self::createClient();

        $card = self::card($this->catalogue($client, 'cs', 'Puzzle 7'), PuzzleFixture::PUZZLE_1000_02);
        self::assertSame([['cs', 'Kouzelná zahrada']], self::localLines($card));
        self::assertCount(0, $card->filter('.puzzle-name-matched'));

        $card = self::card($this->catalogue($client, 'de', 'Puzzle 7'), PuzzleFixture::PUZZLE_1000_02);
        self::assertSame([['de', 'Zauberhafter Garten']], self::localLines($card));

        $card = self::card($this->catalogue($client, 'en', 'Puzzle 7'), PuzzleFixture::PUZZLE_1000_02);
        self::assertSame([], self::localLines($card), 'an English page for a guest');
    }

    public function testCatalogueSearchSaysWhichNameMatched(): void
    {
        $client = self::createClient();

        // Found by its German name on a Czech page: the Czech line is shown, the German one says why it is listed
        $card = self::card($this->catalogue($client, 'cs', 'zauberhafter'), PuzzleFixture::PUZZLE_1000_02);
        self::assertSame([['cs', 'Kouzelná zahrada']], self::localLines($card));
        $matched = $card->filter('.puzzle-name-matched');
        self::assertSame('Shoda: Zauberhafter Garten', $matched->text());
        self::assertSame('de', $matched->filter('b')->attr('lang'));
        self::assertCount(1, $matched->filter('b .search-highlight'));

        // The shown line says it already
        $card = self::card($this->catalogue($client, 'cs', 'kouzelna'), PuzzleFixture::PUZZLE_1000_02);
        self::assertCount(0, $card->filter('.puzzle-name-matched'));

        // An English page has no second line, so the Czech name that matched is named - an untagged one without lang
        $crawler = $this->catalogue($client, 'en', 'kouzelna');
        self::assertSame('Matched: Kouzelná zahrada', self::card($crawler, PuzzleFixture::PUZZLE_1000_02)->filter('.puzzle-name-matched')->text());
        $untagged = self::card($crawler, PuzzleFixture::PUZZLE_300)->filter('.puzzle-name-matched');
        self::assertSame('Matched: Kouzelna zahrada', $untagged->text());
        self::assertNull($untagged->filter('b')->attr('lang'));

        // Found by its main title: nothing to explain
        $card = self::card($this->catalogue($client, 'en', 'Puzzle 7'), PuzzleFixture::PUZZLE_1000_02);
        self::assertCount(0, $card->filter('.puzzle-name-matched'));
    }

    public function testMatchedNameIsEscaped(): void
    {
        $client = self::createClient();
        self::renamePuzzle(PuzzleFixture::PUZZLE_1000_02, 'Puzzle 7', PuzzleNames::fromArray([
            ['name' => '<img src=x onerror=alert(1)> Zahrada', 'language' => 'cs'],
            ['name' => '<script>alert(2)</script> Garten', 'language' => 'de'],
        ]));

        $html = $this->globalSearchHtml($client, 'cs', 'garten');

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;img src=x onerror=', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testHeaderSearchShowsTheSecondLineAndTheMatchedName(): void
    {
        $client = self::createClient();

        $crawler = new Crawler($this->globalSearchHtml($client, 'cs', 'Zauberhafter'));
        $row = $crawler->filter('.global-search-results .puzzle-name')->reduce(
            static fn (Crawler $name): bool => str_ends_with((string) $name->filter('a')->attr('href'), '/' . PuzzleFixture::PUZZLE_1000_02),
        );
        self::assertCount(1, $row);
        self::assertSame([['cs', 'Kouzelná zahrada']], self::localLines($row));
        self::assertSame('Shoda: Zauberhafter Garten', $row->filter('.puzzle-name-matched')->text());
    }

    public function testCollectionListShowsTheNameInTheViewersLanguage(): void
    {
        $client = self::createClient();
        // PLAYER_REGULAR (Czechia) keeps PUZZLE_1000_02 in the library
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $crawler = $client->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();

        $item = $crawler->filter('[data-puzzle-id="' . PuzzleFixture::PUZZLE_1000_02 . '"]');
        self::assertCount(1, $item);
        self::assertSame([['cs', 'Kouzelná zahrada']], self::localLines($item));
    }

    public function testSellSwapListShowsTheNameInThePageLanguage(): void
    {
        $client = self::createClient();

        // PLAYER_ADMIN offers PUZZLE_1000_02
        $crawler = $client->request('GET', '/de/verkaufs-tausch-liste/' . PlayerFixture::PLAYER_ADMIN);
        $this->assertResponseIsSuccessful();

        $item = $crawler->filter('#library-sell-swap-' . PuzzleFixture::PUZZLE_1000_02);
        self::assertCount(1, $item);
        self::assertSame([['de', 'Zauberhafter Garten']], self::localLines($item));
    }

    public function testMultiscanRowShowsTheNameInThePageLanguage(): void
    {
        $client = self::createClient();
        self::renamePuzzle(PuzzleFixture::PUZZLE_300, 'Puzzle 11', PuzzleNames::fromArray([
            ['name' => 'Kouzelná zahrada', 'language' => 'cs'],
        ]));

        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $tray = $this->createLiveComponent('MultiscanTray', [], $client);
        $tray->setRouteLocale('cs');
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_300]);

        $row = (new Crawler($tray->render()->toString()))->filter('.multiscan-row');
        self::assertCount(1, $row);
        self::assertSame([['cs', 'Kouzelná zahrada']], self::localLines($row));
    }

    private function catalogue(KernelBrowser $client, string $locale, string $search): Crawler
    {
        $component = $this->createLiveComponent('PuzzleSearch', ['search' => $search], $client);
        $component->setRouteLocale($locale);

        return new Crawler($component->render()->toString());
    }

    private function globalSearchHtml(KernelBrowser $client, string $locale, string $query): string
    {
        $component = $this->createLiveComponent('GlobalSearch', ['query' => $query], $client);
        $component->setRouteLocale($locale);

        return $component->render()->toString();
    }

    private static function card(Crawler $crawler, string $puzzleId): Crawler
    {
        $card = $crawler->filter('#puzzle-list-item-' . $puzzleId);
        self::assertCount(1, $card, 'The puzzle is listed');

        return $card;
    }

    /**
     * @return list<array{null|string, string}> language and text of every second line
     */
    private static function localLines(Crawler $within): array
    {
        return $within->filter('.puzzle-name-local')->each(
            static fn (Crawler $line): array => [$line->attr('lang'), $line->text()],
        );
    }
}
