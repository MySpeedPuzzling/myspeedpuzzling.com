<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\ArrayParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetXpHistory;
use SpeedPuzzling\Web\Services\Xp\XpRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Workstream D of the progression launch (docs/features/xp-levels/launch-2026-10.md): the "How progress works" hub,
 * the achievements catalogue, the holders page, the leaderboards, the grouped XP history and the puzzle page's XP
 * estimate. The tests run launched (the switch on, FixedXpLaunchReadiness) and look at the pages as a guest, a free
 * player and a member.
 */
final class ProgressionPagesTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testHubIsPublicAndGroupedByFamily(): void
    {
        // Before the XP launch (the switch off): only admins see progression
        FixedXpLaunchReadiness::$ready = false;
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/how-progress-works');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'How progress works');
        $groups = $crawler->filter('.progression-hub-group')->each(static fn ($node): string => $node->text());
        self::assertSame(['Just you', 'Against the clock', 'For everyone'], $groups);
        self::assertSelectorTextContains('.progression-hub-which', 'None of them turns into another');

        // The gate is closed for guests until launch: the cards to gated pages lead nowhere, methodology ones do
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('/en/how-xp-works', $content);
        self::assertStringNotContainsString('/en/achievements', $content);
        self::assertStringContainsString('/en/methodology#msp-rating', $content);
        self::assertStringContainsString('/en/methodology#skill-tier', $content);
    }

    public function testHubCardsLinkTheDeepPagesOnceTheGateIsOpen(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/how-progress-works');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('a.progression-hub-row')->each(static fn ($node): string => (string) $node->attr('href'));
        self::assertSame([
            '/en/how-xp-works',
            '/en/achievements#how-achievements-work',
            '/en/methodology#msp-rating',
            '/en/methodology#skill-tier',
            '/en/how-karma-works',
        ], $links);
        // The footer's Learn column links the hub
        self::assertCount(1, $crawler->filter('.footer-links a[href="/en/how-progress-works"]'));
    }

    public function testUserMenuLinksAchievementsXpHistoryAndHubForAMember(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/faq');

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('.navbar-tool.dropdown .dropdown-menu');
        self::assertCount(1, $menu->filter('a[href="/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE . '"]'));
        self::assertCount(1, $menu->filter('a[href="/en/my/xp-history"]'));
        // The hub is top-level: the menu link carries the page it was opened on, for its back button
        self::assertCount(1, $menu->filter('a[href="/en/how-progress-works?return=/en/faq"]'));
    }

    public function testCatalogueForAGuestIsTheReferenceOfEveryTier(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        self::assertCount(17, $crawler->filter('article.achievement-ref'));
        // Requirements are text, never a hover title
        self::assertStringContainsString('Solve 10 distinct puzzles', $crawler->filter('article#puzzles_solved')->text());
        self::assertCount(0, $crawler->filter('.achievement-refs [title]'));
        self::assertSelectorExists('a.btn[href="/en/membership"]');
        self::assertSelectorNotExists('.achievements-summary');
        self::assertSelectorNotExists('.achievement-progress');
    }

    public function testCatalogueNeverTellsAFreePlayerWhichAchievementsTheyHold(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', 1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/achievements?show=earned');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.achievements-summary');
        self::assertSelectorNotExists('.badge-art-locked');
        self::assertSelectorNotExists('.achievement-progress');
        self::assertStringNotContainsString('Highest tier earned', $crawler->filter('.achievement-refs')->text());
    }

    public function testCatalogueForAMemberHasTheirLineOnTopAndTheSameReference(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievements-summary', '1 of 16 achievements');
        self::assertSelectorExists('.achievements-summary .ap-chip');
        self::assertSelectorNotExists('.achievements-summary .xp-level-chip');
        self::assertCount(17, $crawler->filter('article.achievement-ref'));
        self::assertSelectorNotExists('.achievement-shelf');
    }

    public function testCatalogueRunsOneRarityAggregate(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/achievements?show=all');

        self::assertResponseIsSuccessful();
        $rarity = array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'GROUP BY b.type, COALESCE(b.tier, 0)'));
        self::assertCount(1, $rarity, 'the rarity of every tier comes from one aggregate statement');
        $this->assertQueryCountAtMost($browser, 30, 'achievements catalogue (member)');
    }

    public function testInfoModalShowsTierRowsWithRarityAndNoEarnedStateForAFreePlayer(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', 1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/achievements/puzzles_solved/info', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('li.medal-tier'));
        self::assertCount(5, $crawler->filter('.medal-tier-requirement'));
        self::assertSelectorNotExists('.medal-tier-check');
        self::assertStringNotContainsString('AP', $crawler->filter('li.medal-tier')->text());
        self::assertSelectorExists('.btn-close[aria-label="Close"]');
    }

    public function testInfoModalShowsTheMembersOwnTiers(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/achievements/puzzles_solved/info', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.medal-tier-check'));
        self::assertStringContainsString('+5 AP', $crawler->filter('li.medal-tier')->first()->text());
    }

    public function testHoldersPageUsesCountryNamesAndTheViewersTierAsHero(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1);
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 2);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/achievements/puzzles_solved');

        self::assertResponseIsSuccessful();
        // The member holder is listed, in a row, with the country's name in the filter
        self::assertGreaterThan(0, $crawler->filter('.holder-row')->count());
        $options = $crawler->filter('#holder-country option')->each(static fn ($node): string => $node->text());
        self::assertContains('United Kingdom', $options);
        // The site's type-to-find country picker, with flags
        self::assertSelectorExists('select#holder-country[data-controller="country-select"] option[data-icon="fi fi-gb"]');
        self::assertSelectorNotExists('select[onchange]');
        // The hero is the viewer's Silver, not the Diamond
        $hero = (string) $crawler->filter('.achievement-hero img.badge-art')->attr('src');
        self::assertStringContainsString('puzzles_solved', $hero);
        self::assertStringNotContainsString('-t5', $hero);
        self::assertSelectorTextContains('.achievement-hero', 'You hold Silver');
    }

    public function testLadderHasNoPromoBannersAndTheTimesDropdownOnlyHoldsTimeLadders(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/ladder');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Leaderboards');
        self::assertCount(0, $crawler->filter('.alert-primary'));
        // The boards are the pill row; the dropdown is the sub-choice inside "Times" only
        self::assertCount(0, $crawler->filter('.dropdown-menu a[href="/en/msp-rating"]'));
        self::assertCount(0, $crawler->filter('.dropdown-menu a[href^="/en/ladder/level"]'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideLeaderboardPages(): array
    {
        return [
            'times overview' => ['/en/ladder', 'Times'],
            'times per pieces' => ['/en/ladder/solo/500-pieces', 'Times'],
            'msp rating' => ['/en/msp-rating', 'MSP Rating'],
            'level' => ['/en/ladder/level', 'Level (XP)'],
            'achievement points' => ['/en/ladder/achievement-points', 'Achievement Points'],
            'karma' => ['/en/ladder/karma', 'Karma'],
        ];
    }

    #[DataProvider('provideLeaderboardPages')]
    public function testEveryLeaderboardHasThePillRowWithItsBoardActive(string $url, string $active): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        $pills = $crawler->filter('nav.leaderboards-nav a.leaderboards-nav-pill');
        self::assertSame(
            ['Times', 'MSP Rating', 'Level (XP)', 'Achievement Points', 'Karma'],
            $pills->each(static fn ($pill): string => trim($pill->text())),
        );
        $current = $crawler->filter('nav.leaderboards-nav a[aria-current="page"]');
        self::assertCount(1, $current);
        self::assertSame($active, trim($current->text()));
        self::assertStringContainsString('active', (string) $current->attr('class'));
    }

    public function testBeforeLaunchNonAdminsSeeOnlyTimesAndMspRatingPills(): void
    {
        FixedXpLaunchReadiness::$ready = false;
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/ladder');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Times', 'MSP Rating'],
            $crawler->filter('nav.leaderboards-nav a')->each(static fn ($pill): string => trim($pill->text())),
        );
    }

    public function testBeforeLaunchAdminsSeeEveryPill(): void
    {
        FixedXpLaunchReadiness::$ready = false;
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/msp-rating');

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('nav.leaderboards-nav a'));
    }

    public function testMspRatingLadderSaysWhatItIsAndHasColumnHeaders(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/msp-rating');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '500 pieces');
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('head-to-head', $content);
        self::assertStringContainsString('harder puzzles count more', $content);
    }

    public function testXpLeaderboardUsesTheSharedRowAndTheFavoritesBoardHoldsTheViewer(): void
    {
        $browser = self::createClient();
        $this->setXp([PlayerFixture::PLAYER_REGULAR => 900, PlayerFixture::PLAYER_WITH_STRIPE => 400, PlayerFixture::PLAYER_ADMIN => 100]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/ladder/level?favorites=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table.lb-table');
        self::assertSelectorNotExists('.xp-level-chip');
        // "Me and the people I follow" - the viewer has no favorites, so the board is just them, at #1
        $rows = $crawler->filter('table.lb-table tbody tr');
        self::assertCount(1, $rows);
        self::assertSame('table-active-player', $rows->attr('class'));
        self::assertStringContainsString('400 XP', $rows->text());
        $this->assertQueryCountAtMost($browser, 30, 'XP leaderboard');
    }

    public function testXpLeaderboardSelfRowFollowsTheCountryFilter(): void
    {
        $browser = self::createClient();
        $this->setXp([PlayerFixture::PLAYER_REGULAR => 900, PlayerFixture::PLAYER_WITH_STRIPE => 400]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // A Czech board: the British viewer is not on it, so no pinned row
        $crawler = $browser->request('GET', '/en/ladder/level?country=cz');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table.lb-table tr.table-active-player'));
        $options = $crawler->filter('#board-country option')->each(static fn ($node): string => $node->text());
        self::assertContains('Czechia', $options);
    }

    public function testApTabAsksAGuestToSignInWithAReturnLink(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/ladder/achievement-points');

        self::assertResponseIsSuccessful();
        $login = $crawler->filter('.xp-teaser a');
        self::assertCount(1, $login);
        self::assertStringContainsString('return=', (string) $login->attr('href'));
    }

    public function testXpHistoryShowsOneBlockPerSolveWithItsTotal(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(XpRecomputer::class)->recomputeForPlayer(PlayerFixture::PLAYER_REGULAR);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $expectedBlocks = min(20, self::getContainer()->get(GetXpHistory::class)->countBlocksForPlayer(PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/my/xp-history');

        self::assertResponseIsSuccessful();
        $blocks = $crawler->filter('.xp-history-block');
        self::assertGreaterThan(0, $expectedBlocks);
        self::assertCount($expectedBlocks, $blocks);

        // Each block: the puzzle once, its lines, and a total that is their sum
        $blocks->each(static function ($block): void {
            self::assertCount(1, $block->filter('.xp-history-subject'));
            $sum = array_sum($block->filter('.xp-history-line-amount')->each(static fn ($amount): int => (int) $amount->text()));
            self::assertSame('+' . $sum . ' XP', trim($block->filter('.xp-history-total')->text()));
        });
        self::assertMatchesRegularExpression('/solve/i', $blocks->first()->filter('.xp-history-lines')->text());
        // The owner-only compact header strip
        self::assertSelectorExists('.player-strip-player');
    }

    public function testXpEstimateForAGuestLeavesTheDifficultyOut(): void
    {
        self::assertStringContainsString('~10 XP', $this->estimateLine(null), 'the base of a 1000-piece puzzle without the difficulty part');
    }

    public function testXpEstimateForAFreePlayerLeavesTheDifficultyOut(): void
    {
        $line = $this->estimateLine(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertStringContainsString('~10 XP', $line);
        self::assertStringNotContainsString('rated', $line);
    }

    public function testXpEstimateForAMemberCountsTheDifficultyIn(): void
    {
        self::assertStringContainsString('~15 XP', $this->estimateLine(PlayerFixture::PLAYER_WITH_STRIPE), 'tier 6 adds 50 %');
    }

    /**
     * The estimate line on a 1000-piece puzzle rated in the hardest difficulty tier that none of the viewers solved
     * yet (a first solve - no repeat discount)
     */
    private function estimateLine(null|string $viewer): string
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $puzzleId = $connection->fetchOne(
            "SELECT p.id FROM puzzle p
             WHERE p.pieces_count = 1000 AND p.approved = true AND p.hide_until IS NULL
               AND NOT EXISTS (
                 SELECT 1 FROM puzzle_solving_time pst
                 WHERE pst.puzzle_id = p.id
                   AND (pst.player_id IN (:viewers) OR pst.team IS NOT NULL)
               )
             ORDER BY p.id LIMIT 1",
            ['viewers' => [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE]],
            ['viewers' => ArrayParameterType::STRING],
        );
        self::assertIsString($puzzleId, 'a 1000-piece puzzle the viewers never solved');
        $connection->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, computed_at)
             VALUES (:puzzleId, 1.5, 6, 'high', 50, NOW())
             ON CONFLICT (puzzle_id) DO UPDATE SET difficulty_tier = 6",
            ['puzzleId' => $puzzleId],
        );

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        $crawler = $browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful();

        return $crawler->filter('#xp-puzzle-estimate')->text();
    }

    /**
     * What the launch does: the xp-system gate opens for everybody. The browser keeps this kernel for its first
     * request only - one request per gate opening.
     */

    private function insertBadge(string $playerId, string $type, int $tier): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier) VALUES (:id, :playerId, :type, NOW(), :tier)',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'type' => $type, 'tier' => $tier],
        );
    }

    /**
     * @param array<string, int> $xpByPlayer
     */
    private function setXp(array $xpByPlayer): void
    {
        $connection = self::getContainer()->get(Connection::class);

        // Only these players are on the board
        $connection->executeStatement('UPDATE player SET xp_total = 0');

        foreach ($xpByPlayer as $playerId => $xp) {
            $connection->executeStatement('UPDATE player SET xp_total = :xp WHERE id = :id', ['xp' => $xp, 'id' => $playerId]);
        }
    }
}
