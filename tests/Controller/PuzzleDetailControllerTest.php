<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleRedirect;
use SpeedPuzzling\Web\Tests\CatalogueTestData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PuzzleDetailControllerTest extends WebTestCase
{
    use CatalogueTestData;

    /**
     * The blurred members-only teaser used to hold these invented values; Google indexed them as facts about
     * every puzzle and showed them as the search snippet.
     */
    private const array INVENTED_INSIGHT_VALUES = [
        'Challenging',
        '12% harder than average',
        '24 solves',
        'Medium confidence',
        '~48min',
        'Range: 35min - 64min',
        '1.3x',
        '1.5x',
        'Moderate',
    ];

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

    public function testPuzzleWithHiddenImageHasNoindexMeta(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_HIDDEN_IMAGE);

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testNormalPuzzleHasIndexMeta(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testUnknownPuzzleReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/' . Uuid::uuid7()->toString());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidPuzzleIdReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/not-a-uuid');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMergedPuzzleRedirectsPermanentlyToSurvivor(): void
    {
        $browser = self::createClient();

        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $clock = $container->get(ClockInterface::class);

        $oldPuzzleId = Uuid::uuid7();
        $entityManager->persist(
            new PuzzleRedirect(
                id: Uuid::uuid7(),
                oldPuzzleId: $oldPuzzleId,
                survivorPuzzleId: Uuid::fromString(PuzzleFixture::PUZZLE_500_01),
                createdAt: $clock->now(),
            ),
        );
        $entityManager->flush();

        $browser->request('GET', '/en/puzzle/' . $oldPuzzleId->toString());

        $this->assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 301);
    }

    public function testAnonymousInsightsTeaserHoldsNoInventedValues(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();

        foreach (self::INVENTED_INSIGHT_VALUES as $inventedValue) {
            self::assertStringNotContainsString($inventedValue, $html);
        }

        // The whole insights block - its toggle in the header and the collapse - stays out of snippets
        self::assertNotNull($crawler->filter('#puzzleInsights')->closest('div[data-nosnippet]'));
        self::assertCount(1, $crawler->filter('div[data-nosnippet] button[data-bs-target="#puzzleInsights"]'));

        // The teaser names what members get, shows only a text-free skeleton and keeps the call to action
        $teaser = $crawler->filter('#puzzleInsights');
        self::assertStringContainsString('Puzzle Difficulty', $teaser->text());
        self::assertStringContainsString('Memorability', $teaser->text());
        self::assertSame('', trim($teaser->filter('[aria-hidden="true"]')->text()));
        self::assertGreaterThan(0, $teaser->filter('[aria-hidden="true"] .placeholder')->count());
        self::assertCount(1, $teaser->filter('button[data-bs-target="#membersExclusiveModal"]'));
    }

    public function testSignedInPlayerWithoutMembershipGetsTheSameTeaser(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();

        foreach (self::INVENTED_INSIGHT_VALUES as $inventedValue) {
            self::assertStringNotContainsString($inventedValue, $html);
        }

        self::assertCount(1, $crawler->filter('#puzzleInsights button[data-bs-target="#membersExclusiveModal"]'));
    }

    public function testMemberSeesInsightsNotTheTeaser(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('#puzzleInsights')->closest('div[data-nosnippet]'));
        self::assertCount(0, $crawler->filter('#puzzleInsights .placeholder'));
        self::assertCount(0, $crawler->filter('#puzzleInsights button[data-bs-target="#membersExclusiveModal"]'));
    }

    public function testActionMenuReturnButtonAndApprovalNoticeAreKeptOutOfSnippets(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '?return=/en/puzzle&return_title=Database');

        $this->assertResponseIsSuccessful();
        // Honoured on div/span/section only - so on the menu's wrapper, not on the ul; the same menu is in the header
        // and in the bar that replaces it on scroll
        self::assertCount(2, $crawler->filter('div.more-menu ul.dropdown-menu'));
        self::assertCount(2, $crawler->filter('div.more-menu[data-nosnippet] ul.dropdown-menu'));
        self::assertCount(1, $crawler->filter('div[data-nosnippet] a.btn[href="/en/puzzle"]'));

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_UNAPPROVED);

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('div.alert-warning[data-nosnippet]'));
    }

    public function testSummaryStatesThePublicFactsOfASolvedPuzzle(): void
    {
        $browser = self::createClient();
        $this->setSoloStatistics(PuzzleFixture::PUZZLE_500_01, count: 12, medianSeconds: 3725, fastestSeconds: 1666);

        // PUZZLE_500_01: Ravensburger, 500 pieces, product number RB-500-001, in WJPC 2024 and Czech Nationals 2024 rounds
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $summary = $crawler->filter('section.puzzle-summary');
        self::assertCount(1, $summary);

        // One sentence, then the codes and events as a list and the solves as a table - never one wall of text
        self::assertSame('Puzzle 1 is a 500-piece jigsaw puzzle by Ravensburger.', $summary->filter('p.puzzle-summary-intro')->text());
        self::assertSame([
            'Product number' => 'RB-500-001',
            'Used at' => 'WJPC 2024 Czech National Championship 2024',
        ], self::summaryFacts($summary));
        self::assertSame([
            ['', 'Solves', 'Median', 'Fastest'],
            ['Solo', '12', '01:02:05', '00:27:46'],
        ], self::summaryTimes($summary));
        self::assertSame(
            ['/en/puzzle/brand/ravensburger', '/en/events/wjpc-2024', '/en/events/czech-nationals-2024'],
            $summary->filter('a')->each(static fn (Crawler $link): null|string => $link->attr('href')),
        );

        // The summary sits at the bottom of the page, under the leaderboard - never above the puzzle's own content
        $html = (string) $browser->getResponse()->getContent();
        self::assertGreaterThan(strpos($html, 'id="puzzleInsights"'), strpos($html, 'class="puzzle-summary'));
        self::assertGreaterThan(strpos($html, 'custom-table'), strpos($html, 'class="puzzle-summary'));
        self::assertStringContainsString('About this puzzle', $crawler->filter('h2.puzzle-summary-heading')->text());
    }

    public function testSummaryNeverTalksAboutMembersOnlyInsights(): void
    {
        $browser = self::createClient();

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_1000_01, PuzzleFixture::PUZZLE_4000] as $puzzleId) {
            $crawler = $browser->request('GET', '/en/puzzle/' . $puzzleId);
            $this->assertResponseIsSuccessful();

            $text = $crawler->filter('section.puzzle-summary')->text() . ' ' . $this->metaDescription($crawler);

            foreach (['difficult', 'harder', 'easier', 'percentile', 'faster than', 'tier'] as $insightWord) {
                self::assertStringNotContainsStringIgnoringCase($insightWord, $text);
            }
        }
    }

    public function testSummaryShowsEveryOtherNameAndEveryEan(): void
    {
        $browser = self::createClient();
        // "Also known as": every other name with its language, on an English page in their stored order
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET alternative_names = CAST(:alternativeNames AS jsonb), ean = '4005556175895, 4005555008385' WHERE id = :puzzleId",
            [
                'puzzleId' => PuzzleFixture::PUZZLE_500_01,
                'alternativeNames' => '[{"name": "Bayerische Romanze", "language": "de"}, {"name": "Bavorská romance", "language": "cs"}]',
            ],
        );

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertSame([
            // Each code on its own line
            'EAN' => '4005556175895 4005555008385',
            'Product number' => 'RB-500-001',
            'Also known as' => 'Bayerische Romanze · German Bavorská romance · Czech',
            'Used at' => 'WJPC 2024 Czech National Championship 2024',
        ], self::summaryFacts($crawler->filter('section.puzzle-summary')));

        // One EAN is enough for the meta description
        self::assertStringStartsWith('Ravensburger Puzzle 1 (500 pieces, EAN 4005556175895): ', $this->metaDescription($crawler));
    }

    public function testPuzzleWithoutTimesInvitesToLogTheFirstOne(): void
    {
        $browser = self::createClient();

        // PUZZLE_4000: nobody solved it yet
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_4000);

        $this->assertResponseIsSuccessful();
        $summary = $crawler->filter('section.puzzle-summary');
        self::assertSame('Puzzle 16 is a 4000-piece jigsaw puzzle by Ravensburger.', $summary->filter('p.puzzle-summary-intro')->text());
        self::assertSame(['EAN' => '4005556999996'], self::summaryFacts($summary));
        self::assertSame([], self::summaryTimes($summary));
        self::assertStringEndsWith('No solve times yet – log yours and be the first on the leaderboard.', $summary->text());

        $addLink = $summary->filter('a[rel="nofollow"]');
        self::assertCount(1, $addLink);
        self::assertSame('/en/puzzle-add/' . PuzzleFixture::PUZZLE_4000, $addLink->attr('href'));

        self::assertSame(
            'Ravensburger Puzzle 16 – 4000-piece jigsaw puzzle, EAN 4005556999996. No solve times yet – log yours and be the first on MySpeedPuzzling.',
            $this->metaDescription($crawler),
        );
    }

    public function testPuzzleSolvedOnlyInPairsHasNoSoloTimesYet(): void
    {
        $browser = self::createClient();

        // PUZZLE_1000_03: one pair solve (4000 s), no solo solve
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03);

        $this->assertResponseIsSuccessful();
        $summary = $crawler->filter('section.puzzle-summary');
        self::assertSame([
            ['', 'Solves', 'Median', 'Fastest'],
            ['Pairs', '1', '–', '01:06:40'],
        ], self::summaryTimes($summary));
        self::assertStringEndsWith('No solo times yet – log yours and be the first on the solo leaderboard.', $summary->text());
        self::assertSame(
            'Ravensburger Puzzle 8 – 1000-piece jigsaw puzzle, EAN 4005556789012. No solo times yet – log yours and be the first on MySpeedPuzzling.',
            $this->metaDescription($crawler),
        );
    }

    public function testPuzzleWithOneSoloSolveDoesNotRepeatTheTimeAsMedian(): void
    {
        $browser = self::createClient();

        // PUZZLE_1500_02: one solo solve (7000 s)
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1500_02);

        $this->assertResponseIsSuccessful();
        self::assertSame([
            ['', 'Solves', 'Median', 'Fastest'],
            ['Solo', '1', '–', '01:56:40'],
        ], self::summaryTimes($crawler->filter('section.puzzle-summary')));
        self::assertSame(
            'Trefl Puzzle 13 (1500 pieces, EAN 5900511101010): fastest solo time 1h 56min so far. Compare your time on MySpeedPuzzling.',
            $this->metaDescription($crawler),
        );
    }

    public function testEmbargoedImageKeepsTheCodesOutOfSummaryAndMetaDescription(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET ean = '4005556000017', identification_number = 'EMBARGO-1' WHERE id = :puzzleId",
            ['puzzleId' => PuzzleFixture::PUZZLE_HIDDEN_IMAGE],
        );

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_HIDDEN_IMAGE);

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('4005556000017', (string) $browser->getResponse()->getContent());

        $summary = $crawler->filter('section.puzzle-summary');
        self::assertSame('Puzzle Hidden Image is a 1000-piece jigsaw puzzle by Ravensburger.', $summary->filter('p.puzzle-summary-intro')->text());
        self::assertArrayNotHasKey('EAN', self::summaryFacts($summary));
        self::assertArrayNotHasKey('Product number', self::summaryFacts($summary));
        self::assertStringNotContainsString('EMBARGO-1', $summary->text());
        self::assertStringNotContainsString('EMBARGO-1', $this->metaDescription($crawler));
    }

    public function testTitleAndMetaDescriptionOfASolvedPuzzle(): void
    {
        $browser = self::createClient();
        $this->setSoloStatistics(PuzzleFixture::PUZZLE_500_01, count: 12, medianSeconds: 3725, fastestSeconds: 1666);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertSame('Ravensburger Puzzle 1 – 500 Piece Puzzle', $crawler->filter('title')->text());

        $description = 'Ravensburger Puzzle 1 (500 pieces): median solo time 1h 2min, fastest 27min 46s from 12 solves. Compare your time on MySpeedPuzzling.';
        self::assertSame($description, $this->metaDescription($crawler));

        // The H1 carries no brand (breadcrumb + brand link already show it), "500&nbsp;pieces" stays
        self::assertSame("Puzzle 1 500\u{a0}pieces", $crawler->filter('h1')->text());

        // PUZZLE_500_01 has marketplace offers: the Product JSON-LD describes it with the meta description
        $product = $crawler->filter('script[type="application/ld+json"]')->reduce(
            static fn (Crawler $script): bool => str_contains($script->text(), '"Product"'),
        );
        self::assertCount(1, $product);
        /** @var array{description: string} $productData */
        $productData = json_decode($product->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($description, $productData['description']);
    }

    public function testTagBadgesLinkTheirCompetitionOrStayPlain(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        foreach ([TagFixture::TAG_WJPC, TagFixture::TAG_ONLINE] as $tagId) {
            $database->executeStatement(
                'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
                ['tagId' => $tagId, 'puzzleId' => PuzzleFixture::PUZZLE_1000_04],
            );
        }

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);

        $this->assertResponseIsSuccessful();

        // WJPC belongs to the (approved) WJPC 2024 event
        $wjpcBadge = $crawler->filter('a.badge')->reduce(static fn (Crawler $badge): bool => trim($badge->text()) === 'WJPC');
        self::assertCount(1, $wjpcBadge);
        self::assertSame('/en/events/wjpc-2024', $wjpcBadge->attr('href'));

        // No competition behind "Online Competition": a plain badge, not a ?tag= filter URL
        $onlineBadge = $crawler->filter('span.badge')->reduce(static fn (Crawler $badge): bool => trim($badge->text()) === 'Online Competition');
        self::assertCount(1, $onlineBadge);
        self::assertCount(0, $crawler->filter('a.badge[href*="tag="]'));

        // The tagged competition is named in the summary too
        self::assertSame('WJPC 2024', self::summaryFacts($crawler->filter('section.puzzle-summary'))['Used at'] ?? null);
    }

    /**
     * Every locale has the summary, title and description translated - no raw placeholder, no missing key.
     */
    #[DataProvider('localesAndPuzzles')]
    public function testSummaryTitleAndDescriptionAreTranslated(string $locale, string $puzzleId): void
    {
        $browser = self::createClient();
        $url = self::getContainer()->get(UrlGeneratorInterface::class)->generate('puzzle_detail', [
            'puzzleId' => $puzzleId,
            '_locale' => $locale,
        ]);

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();

        foreach (
            [
            $crawler->filter('title')->text(),
            $this->metaDescription($crawler),
            $crawler->filter('section.puzzle-summary')->text(),
            ] as $text
        ) {
            self::assertStringNotContainsString('%', $text);
            self::assertStringNotContainsString('puzzle_detail.', $text);
        }
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function localesAndPuzzles(): Generator
    {
        foreach (['cs', 'en', 'es', 'ja', 'fr', 'de'] as $locale) {
            yield $locale . ': times, rounds and product number' => [$locale, PuzzleFixture::PUZZLE_500_01];
            yield $locale . ': solo and pair times' => [$locale, PuzzleFixture::PUZZLE_1000_01];
            yield $locale . ': no times' => [$locale, PuzzleFixture::PUZZLE_4000];
            yield $locale . ': pair time only' => [$locale, PuzzleFixture::PUZZLE_1000_03];
            yield $locale . ': one solo time' => [$locale, PuzzleFixture::PUZZLE_1500_02];
        }
    }

    public function testCzechSummaryHasCzechLabelsAndNumbers(): void
    {
        $browser = self::createClient();
        $this->setSoloStatistics(PuzzleFixture::PUZZLE_300, count: 2496, medianSeconds: 2413, fastestSeconds: 990);

        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_300);
        $this->assertResponseIsSuccessful();
        self::assertSame([
            ['', 'Složení', 'Medián', 'Nejrychlejší'],
            ['Sólo', '2 496', '00:40:13', '00:16:30'],
        ], array_map(
            static fn (array $row): array => array_map(self::normalizedSpaces(...), $row),
            self::summaryTimes($crawler->filter('section.puzzle-summary')),
        ));
        self::assertSame('Ravensburger Puzzle 11 – puzzle 300 dílků', $crawler->filter('title')->text());

        // PUZZLE_1000_01: used at one competition
        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_1000_01);
        $this->assertResponseIsSuccessful();
        self::assertSame('WJPC 2024', self::summaryFacts($crawler->filter('section.puzzle-summary'))['Použito na'] ?? null);
    }

    public function testBreadcrumbLeadsThroughTheIndexableBrandPiecesPage(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        // Ravensburger 500 pieces is an indexable combination: 8 visible puzzles, solved
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertJsonLdIsValid($crawler);

        // The visible line is short - the brand precedes the piece count
        self::assertSame([
            ['Jigsaw Puzzle Database', '/en/puzzle'],
            ['Ravensburger', '/en/puzzle/brand/ravensburger'],
            ["500 pieces", '/en/puzzle/brand/ravensburger/500-pieces'],
            ['Puzzle 1', null],
        ], self::visibleBreadcrumb($crawler));

        self::assertSame([
            ['Jigsaw Puzzle Database', 'http://localhost/en/puzzle'],
            ['Ravensburger', 'http://localhost/en/puzzle/brand/ravensburger'],
            ['Ravensburger 500-Piece Puzzles', 'http://localhost/en/puzzle/brand/ravensburger/500-pieces'],
            ['Puzzle 1', null],
        ], self::breadcrumbJsonLd($crawler));

        // The piece count in the header leads to the same page and still looks like the plain text it was
        $piecesLink = $crawler->filter('#main-content a.text-reset[href="/en/puzzle/brand/ravensburger/500-pieces"]');
        self::assertCount(1, $piecesLink);
        self::assertSame("500\u{a0}pieces", $piecesLink->text());
    }

    public function testBreadcrumbFallsBackToThePiecesHubWhenTheCombinationIsNotIndexable(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        // Trefl has two 500-piece puzzles: its brand × pieces page is noindex, so the 500-piece hub of all brands
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_04);

        $this->assertResponseIsSuccessful();
        self::assertSame([
            ['Jigsaw Puzzle Database', '/en/puzzle'],
            ['Trefl', '/en/puzzle/brand/trefl'],
            ['500 piece puzzles', '/en/puzzle/500-pieces'],
            ['Puzzle 4', null],
        ], self::visibleBreadcrumb($crawler));
        self::assertSame(
            ['Jigsaw Puzzle Database', 'Trefl', '500 piece puzzles', 'Puzzle 4'],
            array_column(self::breadcrumbJsonLd($crawler), 0),
        );

        self::assertCount(1, $crawler->filter('#main-content a.text-reset[href="/en/puzzle/500-pieces"]'));
        self::assertCount(0, $crawler->filter('#main-content a[href="/en/puzzle/brand/trefl/500-pieces"]'));
    }

    public function testOddPieceCountHasNoPiecesLevel(): void
    {
        $browser = self::createClient();

        // 4000 pieces has no hub - neither of all brands nor of Ravensburger
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_4000);

        $this->assertResponseIsSuccessful();
        self::assertSame([
            ['Jigsaw Puzzle Database', '/en/puzzle'],
            ['Ravensburger', '/en/puzzle/brand/ravensburger'],
            ['Puzzle 16', null],
        ], self::visibleBreadcrumb($crawler));
        self::assertCount(3, self::breadcrumbJsonLd($crawler));

        // The piece count stays plain text
        self::assertSame("Puzzle 16 4000\u{a0}pieces", $crawler->filter('h1')->text());
        self::assertCount(0, $crawler->filter('h1 a'));
    }

    public function testBrandWithoutSlugHasNoBrandLevel(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE manufacturer SET slug = NULL WHERE id = :brandId',
            ['brandId' => ManufacturerFixture::MANUFACTURER_TREFL],
        );

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02);

        $this->assertResponseIsSuccessful();
        self::assertSame([
            ['Jigsaw Puzzle Database', '/en/puzzle'],
            ['1000 piece puzzles', '/en/puzzle/1000-pieces'],
            ['Puzzle 7', null],
        ], self::visibleBreadcrumb($crawler));

        // Without its own level the brand stays in the puzzle's name
        self::assertSame(
            ['Jigsaw Puzzle Database', '1000 piece puzzles', 'Trefl Puzzle 7'],
            array_column(self::breadcrumbJsonLd($crawler), 0),
        );
        self::assertCount(0, $crawler->filter('#main-content a[href^="/en/puzzle/brand/"]'));
    }

    public function testEmbargoedPuzzleKeepsItsProductNumberOutOfThePage(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            "UPDATE puzzle SET ean = '4005556000017', identification_number = 'EMBARGO-1' WHERE id = :puzzleId",
            ['puzzleId' => PuzzleFixture::PUZZLE_HIDDEN_IMAGE],
        );
        // A marketplace offer brings in the Product structured data
        $database->executeStatement(
            "INSERT INTO sell_swap_list_item (id, listing_type, price, condition, added_at, published_on_marketplace, reserved, player_id, puzzle_id)
             VALUES (:id, 'sell', 10, 'normal', now(), true, false, :playerId, :puzzleId)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzleId' => PuzzleFixture::PUZZLE_HIDDEN_IMAGE],
        );

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_HIDDEN_IMAGE);

        $this->assertResponseIsSuccessful();
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('EMBARGO-1', $html);
        self::assertStringNotContainsString('4005556000017', $html);

        $product = self::productJsonLd($crawler);
        self::assertArrayNotHasKey('sku', $product);
        self::assertArrayNotHasKey('mpn', $product);
        self::assertArrayNotHasKey('gtin13', $product);

        // A puzzle without an embargo shows its product number
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertStringContainsString('RB-500-001', $crawler->filter('#main-content #puzzleDetails')->text());
        self::assertSame('RB-500-001', self::productJsonLd($crawler)['sku']);
    }

    public function testPhotoLinkAndSharedImagesUseTheLargeStrippedPresetNeverTheOriginal(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle SET image = :image WHERE id = :puzzleId',
            ['image' => 'box-with-exif.jpg', 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        // PUZZLE_500_01 has a marketplace offer, so the page carries Product structured data as well
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        // Link previews, structured data and the "open photo" link get the 1200 px preset with the metadata
        // stripped - the uploaded original can still carry EXIF, including the GPS position
        $largeImage = self::getContainer()->get(ImageThumbnailTwigExtension::class)->thumbnailUrl('box-with-exif.jpg', 'puzzle_large');
        self::assertSame($largeImage, $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame($largeImage, $crawler->filter('meta[name="twitter:image"]')->attr('content'));
        self::assertSame($largeImage, self::productJsonLd($crawler)['image']);
        self::assertSame($largeImage, $crawler->filter('.puzzle-head-image a.gallery-item')->attr('href'));
        self::assertStringNotContainsString('/original/box-with-exif.jpg', (string) $browser->getResponse()->getContent());
    }

    public function testOffersBadgeShowsTheLowestPrice(): void
    {
        $browser = self::createClient();

        // PUZZLE_500_01: one offer on the marketplace, £25 (the other one of the fixtures is not published)
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertSame('1 offer from £25', $crawler->filter('a.puzzle-offers-badge')->text());

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE sell_swap_list_item SET price = 12.5 WHERE id = :id',
            ['id' => SellSwapListItemFixture::SELLSWAP_01],
        );

        // Pence stay, and it is the price of the Product structured data
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertSame('1 offer from £12.50', $crawler->filter('a.puzzle-offers-badge')->text());
        /** @var list<array{price: float, priceCurrency: string}> $offers */
        $offers = self::productJsonLd($crawler)['offers'];
        self::assertSame([12.5, 'GBP'], [$offers[0]['price'], $offers[0]['priceCurrency']]);

        // Every language formats money its own way
        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertSame('1 nabídka od 12,50 £', self::normalizedSpaces($crawler->filter('a.puzzle-offers-badge')->text()));
    }

    public function testOffersBadgeWithoutAPriceStaysAsItWas(): void
    {
        $browser = self::createClient();

        // PUZZLE_500_02: one swap offer, no price
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02);

        $this->assertResponseIsSuccessful();
        self::assertSame('1 offers', $crawler->filter('a.puzzle-offers-badge')->text());
    }

    public function testRelatedPuzzlesShareTheBrandAndPieceCount(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $related = $crawler->filter('.puzzle-related');
        self::assertSame('More Ravensburger 500-piece puzzles', $related->filter('h2')->text());

        // Puzzle 2, Puzzle 3 and Intel Test Puzzle A are the most solved, Intel Test Puzzle B the only other solved one
        self::assertSame(
            [
                '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02,
                '/en/puzzle/' . PuzzleFixture::PUZZLE_500_03,
                '/en/puzzle/018d0008-0000-0000-0000-000000000001',
                '/en/puzzle/018d0008-0000-0000-0000-000000000002',
            ],
            $related->filter('a.puzzle-related-card')->extract(['href']),
        );
        // The heading names the piece count, the cards do not repeat it
        self::assertStringNotContainsString('pieces', $related->filter('.puzzle-related-list')->text());

        $allLink = $related->filter('p a');
        self::assertSame('All Ravensburger 500-piece puzzles', $allLink->text());
        self::assertSame('/en/puzzle/brand/ravensburger/500-pieces', $allLink->attr('href'));
    }

    /**
     * The related puzzles and "About this puzzle" serve search engines and guests - a signed-in player has those facts
     * higher up, and the related puzzles' query does not run for them.
     */
    public function testSignedInPlayerSeesNeitherRelatedPuzzlesNorTheSummary(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.puzzle-related'));
        self::assertCount(0, $crawler->filter('section.puzzle-summary'));
        self::assertCount(0, $crawler->filter('h2.puzzle-summary-heading'));
    }

    public function testRelatedPuzzlesFallBackToTheWholeBrand(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        // Trefl has one other 1000-piece puzzle - too few for the module
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);

        $this->assertResponseIsSuccessful();
        $related = $crawler->filter('.puzzle-related');
        self::assertSame('More Trefl puzzles', $related->filter('h2')->text());
        self::assertSame(
            ["1000\u{a0}pieces", "500\u{a0}pieces", "1500\u{a0}pieces"],
            $related->filter('a.puzzle-related-card small')->each(static fn (Crawler $pieces): string => $pieces->text()),
        );

        $allLink = $related->filter('p a');
        self::assertSame('View all Trefl puzzles', $allLink->text());
        self::assertSame('/en/puzzle/brand/trefl', $allLink->attr('href'));
    }

    /**
     * The breadcrumb, the related puzzles and the offers badge are translated in every locale.
     */
    #[DataProvider('locales')]
    public function testBreadcrumbRelatedPuzzlesAndOffersAreTranslated(string $locale): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_1000_04] as $puzzleId) {
            $crawler = $browser->request('GET', $urlGenerator->generate('puzzle_detail', ['puzzleId' => $puzzleId, '_locale' => $locale]));
            $this->assertResponseIsSuccessful();

            $texts = [
                $crawler->filter('nav.puzzle-breadcrumb')->text(),
                $crawler->filter('.puzzle-related h2')->text(),
                $crawler->filter('.puzzle-related p a')->text(),
                ...$crawler->filter('a.puzzle-offers-badge')->each(static fn (Crawler $badge): string => $badge->text()),
            ];

            foreach ($texts as $text) {
                self::assertStringNotContainsString('%', $text);
                self::assertDoesNotMatchRegularExpression('/(puzzle_detail|puzzler_offers|brand_pieces_hub|brand_hub)\./', $text);
            }
        }
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function locales(): Generator
    {
        foreach (['cs', 'en', 'es', 'ja', 'fr', 'de'] as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * @return list<array{string, null|string}> Label and link of every level of the visible breadcrumb
     */
    private static function visibleBreadcrumb(Crawler $crawler): array
    {
        return $crawler->filter('nav.puzzle-breadcrumb li')->each(static function (Crawler $level): array {
            $link = $level->filter('a');

            return [$level->text(), $link->count() > 0 ? $link->attr('href') : null];
        });
    }

    /**
     * @return list<array{string, null|string}> Name and URL of every level of the BreadcrumbList JSON-LD
     */
    private static function breadcrumbJsonLd(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode((string) $script->textContent, true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($data) || ($data['@type'] ?? null) !== 'BreadcrumbList') {
                continue;
            }

            /** @var list<array{position: int, name: string, item?: string}> $items */
            $items = $data['itemListElement'];
            self::assertSame(range(1, count($items)), array_column($items, 'position'));

            return array_map(static fn (array $item): array => [$item['name'], $item['item'] ?? null], $items);
        }

        self::fail('The page has no BreadcrumbList JSON-LD');
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

    private static function normalizedSpaces(string $text): string
    {
        return str_replace(["\u{a0}", "\u{202f}"], ' ', $text);
    }

    /**
     * @return array<string, string> label => value of the summary's facts list
     */
    private static function summaryFacts(Crawler $summary): array
    {
        return array_combine(
            $summary->filter('dl.puzzle-summary-facts dt')->each(static fn (Crawler $label): string => $label->text()),
            $summary->filter('dl.puzzle-summary-facts dd')->each(static fn (Crawler $value): string => $value->text()),
        );
    }

    /**
     * @return list<list<string>> the summary's table of solves, header row first
     */
    private static function summaryTimes(Crawler $summary): array
    {
        return $summary->filter('table.puzzle-summary-times tr')->each(
            static fn (Crawler $row): array => $row->filter('th, td')->each(static fn (Crawler $cell): string => $cell->text()),
        );
    }

    private function setSoloStatistics(string $puzzleId, int $count, int $medianSeconds, int $fastestSeconds): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_statistics SET solved_times_solo_count = :count, median_time_solo = :median, fastest_time_solo = :fastest WHERE puzzle_id = :puzzleId',
            ['count' => $count, 'median' => $medianSeconds, 'fastest' => $fastestSeconds, 'puzzleId' => $puzzleId],
        );
    }

    private function metaDescription(Crawler $crawler): string
    {
        return (string) $crawler->filter('meta[name="description"]')->attr('content');
    }
}
