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
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PuzzleDetailControllerTest extends WebTestCase
{
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

        // The whole insights block - toggle row and collapse - stays out of snippets
        $insights = $crawler->filter('#puzzleInsights')->closest('div[data-nosnippet]');
        self::assertNotNull($insights);
        self::assertCount(1, $insights->filter('button[data-bs-target="#puzzleInsights"]'));

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
        // Honoured on div/span/section only - so on the menu's wrapper, not on the ul
        self::assertCount(1, $crawler->filter('div.dropdown[data-nosnippet] ul.dropdown-menu'));
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

        self::assertSame(
            'Puzzle 1 is a 500-piece jigsaw puzzle by Ravensburger (product number RB-500-001). '
            . '12 solo solves logged: median 1h 2min, fastest 27min 46s. '
            . 'Used at WJPC 2024 and Czech National Championship 2024.',
            $summary->text(),
        );
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

    public function testSummaryShowsAlternativeNameAndEveryEan(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET alternative_name = 'Bavorská romance', ean = '4005556175895, 4005555008385' WHERE id = :puzzleId",
            ['puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $summary = $crawler->filter('section.puzzle-summary')->text();
        self::assertStringContainsString('(EAN 4005556175895 / 4005555008385, product number RB-500-001).', $summary);
        self::assertStringContainsString('Also known as Bavorská romance.', $summary);

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
        self::assertSame(
            'Puzzle 16 is a 4000-piece jigsaw puzzle by Ravensburger (EAN 4005556999996). '
            . 'No solve times yet – log yours and be the first on the leaderboard.',
            $summary->text(),
        );

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
        self::assertSame(
            'Puzzle 8 is a 1000-piece jigsaw puzzle by Ravensburger (EAN 4005556789012). '
            . 'Pairs: one solve (1h 6min). '
            . 'No solo times yet – log yours and be the first on the solo leaderboard.',
            $crawler->filter('section.puzzle-summary')->text(),
        );
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
        self::assertStringEndsWith('One solo solve logged so far: 1h 56min.', $crawler->filter('section.puzzle-summary')->text());
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

        $summary = $crawler->filter('section.puzzle-summary')->text();
        self::assertStringStartsWith('Puzzle Hidden Image is a 1000-piece jigsaw puzzle by Ravensburger. ', $summary);
        self::assertStringNotContainsString('EMBARGO-1', $summary);
        self::assertStringNotContainsString('EMBARGO-1', $this->metaDescription($crawler));
    }

    public function testTitleAndMetaDescriptionOfASolvedPuzzle(): void
    {
        $browser = self::createClient();
        $this->setSoloStatistics(PuzzleFixture::PUZZLE_500_01, count: 12, medianSeconds: 3725, fastestSeconds: 1666);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertSame('Ravensburger Puzzle 1 – 500 Piece Puzzle – MySpeedPuzzling', $crawler->filter('title')->text());

        $description = 'Ravensburger Puzzle 1 (500 pieces): median solo time 1h 2min, fastest 27min 46s from 12 solves. Compare your time on MySpeedPuzzling.';
        self::assertSame($description, $this->metaDescription($crawler));

        // The H1 stays as it was ("500&nbsp;pieces")
        self::assertSame("Ravensburger Puzzle 1 (500\u{a0}pieces)", $crawler->filter('h1')->text());

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
        self::assertStringContainsString('Used at WJPC 2024.', $crawler->filter('section.puzzle-summary')->text());
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

    public function testCzechSummaryAgreesWithTheCount(): void
    {
        $browser = self::createClient();

        // PUZZLE_300: two solo solves; PUZZLE_1000_01: used at one competition
        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_300);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Zaznamenána 2 sólo složení', $crawler->filter('section.puzzle-summary')->text());
        self::assertSame('Ravensburger Puzzle 11 – puzzle 300 dílků – MySpeedPuzzling', $crawler->filter('title')->text());

        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_1000_01);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Použito na soutěži WJPC 2024.', $crawler->filter('section.puzzle-summary')->text());
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
