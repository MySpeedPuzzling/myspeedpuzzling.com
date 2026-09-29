<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Services\PuzzleTimeGuides;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Tests\PinsSolveTimeDistributions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The "How long does a {N}-piece puzzle take?" family: one guide per standard size,
 * the pairs & teams guide and the pillar table. Pages are pinned to a production-shaped
 * snapshot (PinsSolveTimeDistributions), except where the fixture data itself is the point.
 */
final class HowLongGuidesControllerTest extends WebTestCase
{
    use PinsSolveTimeDistributions;

    private PuzzlingTimeFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new PuzzlingTimeFormatter();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideGuideSizes(): iterable
    {
        foreach (PuzzleTimeGuides::GENERIC_GUIDE_PIECES as $pieces) {
            yield sprintf('%d pieces', $pieces) => [$pieces];
        }
    }

    #[DataProvider('provideGuideSizes')]
    public function testSizeGuideAnswersWithLiveNumbers(int $pieces): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        $path = sprintf('/en/guides/how-long-does-a-%d-piece-puzzle-take', $pieces);
        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame(sprintf('How Long Does a %d-Piece Puzzle Take?', $pieces), $crawler->filter('h1')->text());

        $title = $crawler->filter('title')->text();
        self::assertSame(sprintf('How Long Does a %d-Piece Puzzle Take? – MySpeedPuzzling', $pieces), $title);
        self::assertLessThanOrEqual(60, mb_strlen($title), 'Title must fit into 60 characters including the site suffix');

        [$medianSeconds, $solves] = self::PINNED_DATA['solo'][$pieces];

        $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringContainsString(number_format($solves), $description);
        self::assertStringContainsString($this->formatter->compactTime($medianSeconds), $description);

        // The hero answers with the measured median and the solves behind it
        $lead = $crawler->filter('.guide-hero-lead')->text();
        self::assertStringContainsString(sprintf('Across %s solo solves', number_format($solves)), $lead);

        $content = (string) $browser->getResponse()->getContent();
        $article = self::jsonLdOfType($content, 'Article');
        self::assertSame(sprintf('How Long Does a %d-Piece Puzzle Take?', $pieces), $article['headline']);
        self::assertSame('2026-09-30', $article['datePublished']);
        self::assertSame('2026-09-28', $article['dateModified'], 'dateModified is the day the numbers were computed');
        self::assertIsArray($article['mainEntityOfPage']);
        self::assertIsString($article['mainEntityOfPage']['@id']);
        self::assertStringEndsWith($path, $article['mainEntityOfPage']['@id']);

        $breadcrumb = self::jsonLdOfType($content, 'BreadcrumbList');
        self::assertIsArray($breadcrumb['itemListElement']);
        self::assertCount(3, $breadcrumb['itemListElement']);

        // Self-referencing canonical and a single English hreflang pair
        self::assertStringEndsWith($path, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame(2, substr_count($content, 'rel="alternate" hreflang='));
        self::assertStringEndsWith($path, (string) $crawler->filter('link[hreflang="en"]')->attr('href'));

        // Links to the size's puzzles, the pillar table and the pairs & teams guide
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href="/en/puzzle/%d-pieces"]', $pieces))->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/guides/average-puzzle-time-by-piece-count"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/guides/how-long-does-a-1000-piece-puzzle-take-with-2-people"]')->count());
    }

    public function testSizeGuideComparesSoloPairsAndTeamsWhenEnoughGroupsSolvedIt(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        $crawler = $browser->request('GET', '/en/guides/how-long-does-a-500-piece-puzzle-take');

        self::assertResponseIsSuccessful();

        $comparison = self::tableWithHeader($crawler, '500 pieces');
        self::assertStringContainsString('Pair (2 people)', $comparison);
        self::assertStringContainsString('Team (3 or more)', $comparison);
        // 3894 s solo vs 2682 s pair
        self::assertStringContainsString('1.5× as fast', $comparison);

        // Neighbours: 300 and 1000 pieces, each linking to its own guide
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/guides/how-long-does-a-300-piece-puzzle-take"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/guides/how-long-does-a-1000-piece-puzzle-take"]')->count());

        // 500 is the competition size - its guide points to the 500-piece leaderboard
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/ladder/solo/500-pieces"]')->count());
    }

    public function testSizeGuideLeavesOutGroupsWithTooFewSolves(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        // 2000 pieces: 29 pair and 40 team solves - neither reaches the minimum
        $crawler = $browser->request('GET', '/en/guides/how-long-does-a-2000-piece-puzzle-take');

        self::assertResponseIsSuccessful();

        $content = $crawler->filter('.guide-article')->text();
        self::assertStringNotContainsString('Pair (2 people)', $content);
        self::assertStringContainsString('Pairs and teams rarely time a 2000-piece puzzle', $content);
    }

    public function testSizeGuideShowsPairsWithoutTeamsWhenOnlyPairsHaveEnoughSolves(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        // 100 pieces: enough pairs (187), too few teams (28)
        $crawler = $browser->request('GET', '/en/guides/how-long-does-a-100-piece-puzzle-take');

        self::assertResponseIsSuccessful();

        $comparison = self::tableWithHeader($crawler, '100 pieces');
        self::assertStringContainsString('Pair (2 people)', $comparison);
        self::assertStringNotContainsString('Team (3 or more)', $comparison);
        self::assertStringContainsString(
            'Teams have fewer than 100 recorded 100-piece solves so far',
            $crawler->filter('.guide-article')->text(),
        );
    }

    public function testSizeWithTooFewSoloSolvesHasNoGuide(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions([2000 => PuzzleTimeGuides::MINIMUM_SOLO_SOLVES - 1]);

        $browser->request('GET', '/en/guides/how-long-does-a-2000-piece-puzzle-take');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSizeGuideIsNotFoundWhileTheFixtureDataIsTooThin(): void
    {
        $browser = self::createClient();

        // Real query over the fixtures: 40 solo 500-piece solves, no 100-piece puzzle at all
        $browser->request('GET', '/en/guides/how-long-does-a-500-piece-puzzle-take');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/guides/how-long-does-a-100-piece-puzzle-take');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedSizes(): iterable
    {
        yield '750 pieces' => ['/en/guides/how-long-does-a-750-piece-puzzle-take'];
        yield '3000 pieces' => ['/en/guides/how-long-does-a-3000-piece-puzzle-take'];
        yield '250 pieces' => ['/en/guides/how-long-does-a-250-piece-puzzle-take'];
        yield 'leading zero' => ['/en/guides/how-long-does-a-0500-piece-puzzle-take'];
        yield 'localized prefix' => ['/de/guides/how-long-does-a-500-piece-puzzle-take'];
    }

    #[DataProvider('provideUnsupportedSizes')]
    public function testUnsupportedSizesAreNotFound(string $path): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        $browser->request('GET', $path);

        self::assertResponseStatusCodeSame(404);
    }

    public function testThousandPiecesStaysOnItsOriginalGuide(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);

        // The generic route never serves 1000 - that URL belongs to the original guide
        $match = $router->match('/en/guides/how-long-does-a-1000-piece-puzzle-take');
        self::assertSame('guide_puzzle_time_by_pieces', $match['_route']);

        $this->expectException(InvalidParameterException::class);
        $router->generate('guide_puzzle_time_for_pieces', ['pieces' => 1000]);
    }

    public function testPairsAndTeamsGuide(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        $path = '/en/guides/how-long-does-a-1000-piece-puzzle-take-with-2-people';
        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame('How Long Does a 1000-Piece Puzzle Take With 2 People?', $crawler->filter('h1')->text());

        $title = $crawler->filter('title')->text();
        self::assertSame('1000-Piece Puzzle With 2 People: How Long? – MySpeedPuzzling', $title);
        self::assertLessThanOrEqual(60, mb_strlen($title));

        // 5,236 pair solves, median 6342 s = 1h 45min, against 11742 s = 3h 15min solo
        $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringContainsString('5,236', $description);
        self::assertStringContainsString('1h 45min', $description);
        self::assertStringContainsString('3h 15min', $description);

        $lead = $crawler->filter('.guide-hero-lead')->text();
        self::assertStringContainsString('1 hour 45 minutes', $lead);
        self::assertStringContainsString('1.9× as fast', $lead);

        // "With 4 people": teams of exactly four (3690 s), three (5256 s), and the share of fours among teams
        $article = $crawler->filter('.guide-article')->text();
        self::assertStringContainsString('How long does a 1000-piece puzzle take with 4 people?', $article);
        self::assertStringContainsString('1 hour 1 minute', $article);
        self::assertStringContainsString('84% of the recorded 1000-piece team solves are groups of exactly four', $article);
        self::assertStringContainsString('the fourth person saves about 26min', $article);

        $groupSizes = self::tableWithHeader($crawler, 'People');
        self::assertStringContainsString('1h 1min', $groupSizes);
        self::assertStringContainsString('30min 24s', $groupSizes);

        $content = (string) $browser->getResponse()->getContent();
        $jsonLdArticle = self::jsonLdOfType($content, 'Article');
        self::assertSame('2026-09-30', $jsonLdArticle['datePublished']);
        self::assertSame('2026-09-28', $jsonLdArticle['dateModified']);
        self::jsonLdOfType($content, 'BreadcrumbList');

        self::assertGreaterThan(0, $crawler->filter('a[href="/en/guides/how-long-does-a-500-piece-puzzle-take"]')->count());
    }

    public function testPairsAndTeamsGuideRendersOnThinData(): void
    {
        $browser = self::createClient();

        // Fixture data: two pair solves, no teams - every section falls back gracefully
        $crawler = $browser->request('GET', '/en/guides/how-long-does-a-1000-piece-puzzle-take-with-2-people');

        self::assertResponseIsSuccessful();
        self::assertSame('How Long Does a 1000-Piece Puzzle Take With 2 People?', $crawler->filter('h1')->text());

        $content = (string) $browser->getResponse()->getContent();
        self::jsonLdOfType($content, 'Article');
        self::jsonLdOfType($content, 'BreadcrumbList');
    }

    public function testAveragePuzzleTimeTableLinksEverySizeGuide(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        $path = '/en/guides/average-puzzle-time-by-piece-count';
        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame('Average Puzzle Time by Piece Count', $crawler->filter('h1')->text());

        $title = $crawler->filter('title')->text();
        self::assertSame('Average Jigsaw Puzzle Time by Piece Count – MySpeedPuzzling', $title);
        self::assertLessThanOrEqual(60, mb_strlen($title));

        $table = $crawler->filter('.guide-table')->first();
        self::assertCount(7, $table->filter('tbody tr'));

        foreach (PuzzleTimeGuides::GENERIC_GUIDE_PIECES as $pieces) {
            self::assertCount(1, $table->filter(sprintf('a[href="/en/guides/how-long-does-a-%d-piece-puzzle-take"]', $pieces)));
        }

        self::assertCount(1, $table->filter('a[href="/en/guides/how-long-does-a-1000-piece-puzzle-take"]'));

        // 1500 pieces: 42 pair / 80 team solves - no group median
        $row1500 = $table->filter('tbody tr')->eq(5);
        self::assertStringContainsString('1,500 pieces', $row1500->text());
        $emptyCells = $row1500->filter('td')->reduce(static fn (Crawler $cell): bool => trim($cell->text()) === '–');
        self::assertCount(2, $emptyCells);

        // 500 pieces: every column filled
        $row500 = $table->filter('tbody tr')->eq(3)->text();
        self::assertStringContainsString('1h 4min', $row500);
        self::assertStringContainsString('44min 42s', $row500);
        self::assertStringContainsString('33min 48s', $row500);

        $content = (string) $browser->getResponse()->getContent();
        $article = self::jsonLdOfType($content, 'Article');
        self::assertSame('2026-09-28', $article['dateModified']);
        self::jsonLdOfType($content, 'BreadcrumbList');

        $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertStringContainsString('1h 4min', $description);
        self::assertStringContainsString('3h 15min', $description);
    }

    public function testOriginalGuideComparesPairsAndTeamsAndLinksTheFamily(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        $crawler = $browser->request('GET', '/en/guides/how-long-does-a-1000-piece-puzzle-take');

        self::assertResponseIsSuccessful();

        $article = $crawler->filter('.guide-article')->text();
        self::assertStringContainsString('1000 pieces with a partner or a team', $article);

        $comparison = self::tableWithHeader($crawler, '1,000 pieces');
        self::assertStringContainsString('Pair (2 people)', $comparison);
        self::assertStringContainsString('1h 45min', $comparison);
        self::assertStringContainsString('Team (3 or more)', $comparison);

        foreach (PuzzleTimeGuides::GENERIC_GUIDE_PIECES as $pieces) {
            self::assertGreaterThan(0, $crawler->filter(sprintf('a[href="/en/guides/how-long-does-a-%d-piece-puzzle-take"]', $pieces))->count());
        }

        self::assertGreaterThan(0, $crawler->filter('a[href="/en/guides/how-long-does-a-1000-piece-puzzle-take-with-2-people"]')->count());

        $article = self::jsonLdOfType((string) $browser->getResponse()->getContent(), 'Article');
        self::assertSame('2026-07-11', $article['datePublished'], 'The original guide keeps its publication date');
        self::assertSame('2026-09-28', $article['dateModified']);
    }

    public function testGuidesIndexListsTheWholeFamily(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();

        $crawler = $browser->request('GET', '/en/guides');

        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('a[href="/en/guides/average-puzzle-time-by-piece-count"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/guides/how-long-does-a-1000-piece-puzzle-take-with-2-people"]')->count());

        $bySize = $crawler->filter('#guides-by-size')->closest('section');
        self::assertNotNull($bySize);
        self::assertCount(7, $bySize->filter('li a'));
        self::assertStringContainsString('How Long Does a 2000-Piece Puzzle Take?', $bySize->text());
    }

    public function testGuidesIndexOnlyListsLiveSizeGuides(): void
    {
        $browser = self::createClient();

        // Fixture data: no generic size reaches the minimum - only the original 1000-piece guide is live
        $crawler = $browser->request('GET', '/en/guides');

        self::assertResponseIsSuccessful();

        $bySize = $crawler->filter('#guides-by-size')->closest('section');
        self::assertNotNull($bySize);
        self::assertCount(1, $bySize->filter('li a'));
        self::assertSame('/en/guides/how-long-does-a-1000-piece-puzzle-take', $bySize->filter('li a')->attr('href'));
    }

    private static function tableWithHeader(Crawler $crawler, string $firstHeader): string
    {
        $tables = $crawler->filter('table')->reduce(
            static fn (Crawler $table): bool => trim($table->filter('thead th')->first()->text()) === $firstHeader,
        );

        self::assertGreaterThan(0, $tables->count(), sprintf('No table whose first column is "%s"', $firstHeader));

        return $tables->first()->text();
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonLdOfType(string $content, string $type): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        foreach ($matches[1] as $json) {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === $type) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        self::fail(sprintf('No %s JSON-LD on the page', $type));
    }
}
