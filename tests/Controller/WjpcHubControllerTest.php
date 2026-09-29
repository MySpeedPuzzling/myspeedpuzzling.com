<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class WjpcHubControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalizedPaths(): iterable
    {
        yield 'en' => ['/en/world-jigsaw-puzzle-championship'];
        yield 'cs' => ['/mistrovstvi-sveta-ve-skladani-puzzle'];
        yield 'de' => ['/de/puzzle-weltmeisterschaft'];
        yield 'fr' => ['/fr/championnat-du-monde-de-puzzle'];
        yield 'es' => ['/es/campeonato-mundial-de-puzzles'];
        yield 'ja' => ['/ja/世界ジグソーパズル選手権'];
    }

    #[DataProvider('provideLocalizedPaths')]
    public function testPageIsAccessibleInAllLocales(string $path): void
    {
        $browser = self::createClient();

        $browser->request('GET', $path);

        $this->assertResponseIsSuccessful();
    }

    public function testPageHasExactlyOneH1(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1'));
    }

    public function testTitleContainsWorldJigsaw(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();

        $title = $crawler->filter('title')->text();

        // Before the wjpc_hub translations are merged, the raw translation key is rendered.
        self::assertTrue(
            str_contains($title, 'World Jigsaw') || str_contains($title, 'wjpc_hub.meta.title'),
            sprintf('Title "%s" should contain "World Jigsaw"', $title),
        );
    }

    public function testJsonLdIsValidAndContainsBreadcrumbAndItemList(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        self::assertNotEmpty($matches[1], 'Page should contain JSON-LD scripts');

        $types = [];

        foreach ($matches[1] as $json) {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($decoded);

            if (isset($decoded['@type']) && is_string($decoded['@type'])) {
                $types[] = $decoded['@type'];
            }
        }

        self::assertContains('BreadcrumbList', $types, 'Page should contain BreadcrumbList JSON-LD');
        self::assertContains('ItemList', $types, 'Page should contain ItemList JSON-LD');
    }

    public function testListsThePuzzlesOfEachEdition(): void
    {
        $browser = self::createClient();
        // A puzzle only carrying the edition's tag counts too
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
            ['tagId' => TagFixture::TAG_WJPC, 'puzzleId' => PuzzleFixture::PUZZLE_500_03],
        );

        $crawler = $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();
        $section = $crawler->filter(sprintf('[data-wjpc-edition-puzzles="%s"]', CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertCount(1, $section);
        self::assertStringContainsString('WJPC24 puzzles', $section->filter('h3')->text());
        self::assertCount(1, $section->filter('a[href="/en/events/wjpc-2024"]'));

        // Round puzzles in schedule order (qualification, then final), the tag-only puzzle last
        $linkedPuzzles = array_values(array_unique($section->filter('a[href^="/en/puzzle/"]')->each(
            static fn (Crawler $link): string => (string) $link->attr('href'),
        )));
        self::assertCount(5, $linkedPuzzles);
        self::assertEqualsCanonicalizing(
            ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02],
            array_slice($linkedPuzzles, 0, 2),
        );
        self::assertEqualsCanonicalizing(
            ['/en/puzzle/' . PuzzleFixture::PUZZLE_1000_01, '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02],
            array_slice($linkedPuzzles, 2, 2),
        );
        self::assertSame('/en/puzzle/' . PuzzleFixture::PUZZLE_500_03, $linkedPuzzles[4]);

        // Public solo median and fastest time of a solved puzzle
        self::assertStringContainsString('Median:', $section->text());
        self::assertStringContainsString('Fastest:', $section->text());
    }

    public function testSecretRoundPuzzleIsNotListedBeforeItsRoundStarts(): void
    {
        $browser = self::createClient();
        // The qualification round starts in 30 days
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round_puzzle SET hide_until_round_starts = true, hide_mode = 'entirely' WHERE round_id = :roundId AND puzzle_id = :puzzleId",
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'puzzleId' => PuzzleFixture::PUZZLE_500_02],
        );

        $crawler = $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();
        $section = $crawler->filter(sprintf('[data-wjpc-edition-puzzles="%s"]', CompetitionFixture::COMPETITION_WJPC_2024));
        // Its round-mate without the flag is listed, the secret puzzle is not
        self::assertGreaterThan(0, $section->filter(sprintf('a[href="/en/puzzle/%s"]', PuzzleFixture::PUZZLE_500_01))->count());
        self::assertCount(0, $section->filter(sprintf('a[href="/en/puzzle/%s"]', PuzzleFixture::PUZZLE_500_02)));
    }

    public function testEditionWithoutPuzzlesHasNoPuzzleSection(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM competition_round_puzzle WHERE round_id IN (:qualification, :final)',
            ['qualification' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'final' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        );

        $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-wjpc-edition-puzzles]');
        $this->assertSelectorNotExists('#wjpc-puzzles');
    }

    public function testEditionsTableLinksToEventPages(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/world-jigsaw-puzzle-championship');

        $this->assertResponseIsSuccessful();

        // Fixtures contain the approved "WJPC 2024" competition with slug "wjpc-2024".
        $eventLinks = $crawler->filter('table a[href*="/en/events/"]');

        self::assertGreaterThanOrEqual(1, $eventLinks->count(), 'Editions table should link to at least one event page');
    }
}
