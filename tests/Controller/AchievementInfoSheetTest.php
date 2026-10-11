<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The achievement detail sheet (AchievementInfoController, docs/features/xp-levels/ux-polish/design-spec.md §2 + §8):
 * the medal that turns over, the facts line, the tier list, whose medal (?player=) and its query budget.
 * The fixtures' launch marker is 2026-08-02 (XpSettingsFixture): earlier tiers are "held at launch".
 */
final class AchievementInfoSheetTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string TYPE = 'puzzles_solved';

    private const string SHEET = '/en/achievements/' . self::TYPE . '/info';

    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;

    public function testAMemberSeesTheirOwnMedalWithItsNumberAndTheTiersHeldAtLaunch(): void
    {
        $browser = self::createClient();
        $this->giveMemberGoldAfterLaunch();
        TestingLogin::asPlayer($browser, self::MEMBER);

        $this->startCountingQueries($browser);
        $crawler = $this->sheet($browser);

        // The bottom sheet is opted in by the content only; no history entry (design-spec §8.3)
        self::assertCount(1, $crawler->filter('.achievement-sheet[data-controller="achievement-sheet"]'));
        self::assertCount(0, $crawler->filter('[data-modal-history]'));

        // The medal is the viewer's highest tier, a button that turns over to the back of Gold
        $card = $crawler->filter('.medal button.medal-card');
        self::assertCount(1, $card);
        self::assertSame('false', $card->attr('aria-pressed'));
        self::assertSame('medal-back-3', $card->attr('aria-describedby'));
        self::assertStringContainsString('/puzzles_solved-t3.webp', (string) $crawler->filter('img.medal-art')->attr('src'));

        $gold = $crawler->filter('#medal-back-3');
        self::assertStringContainsString('No. 0001', $gold->text());
        self::assertStringContainsString('of 1', $gold->text());
        self::assertStringNotContainsString('Held at launch', $gold->text());
        self::assertCount(1, $crawler->filter('#medal-back-1 .medal-engraving-launch'));
        self::assertCount(0, $crawler->filter('#medal-back-1 .medal-engraving-date'));

        // The facts line repeats what the back says
        $facts = $crawler->filter('.medal-facts-tier[data-tier="3"]');
        self::assertNull($facts->attr('hidden'));
        self::assertStringContainsString('No. 1 of 1', $facts->text());
        // How many hold it and their share - no XP denominator (Jan 2026-10-11)
        self::assertMatchesRegularExpression('/Held by \d+ puzzlers? \(/', $facts->text());
        self::assertStringNotContainsString('XP', $facts->text());
        self::assertStringContainsString('Held at launch', $crawler->filter('.medal-facts-tier[data-tier="1"]')->text());
        self::assertStringContainsString('Nobody yet', $crawler->filter('.medal-facts-tier[data-tier="5"]')->text());

        // Five 52 px tier rows; the member's ticks and AP; a row is a button showing its tier on the medal
        self::assertCount(5, $crawler->filter('li.medal-tier'));
        self::assertCount(3, $crawler->filter('li.medal-tier .medal-tier-check'));
        self::assertStringContainsString('+5 AP', $crawler->filter('li.medal-tier')->first()->text());
        self::assertSame('true', $crawler->filter('li.medal-tier[data-tier="3"] button.medal-tier-name')->attr('aria-pressed'));
        self::assertNotNull($crawler->filter('li.medal-tier[data-tier="3"]')->attr('data-holo'));
        self::assertNull($crawler->filter('li.medal-tier[data-tier="4"]')->attr('data-holo'));

        self::assertSelectorTextContains('.medal-actions a.btn-outline-primary', 'See who earned it (');
        self::assertCount(2, $crawler->filter('.medal-actions a[data-turbo-frame="_top"]'));
        self::assertSelectorTextContains('.medal-footnote', 'counts everyone who has logged at least one puzzle');

        // One statement for the medal facts of every earned tier, and a pinned budget for the whole sheet. Measured
        // 2026-10-11: sign-in + locale (7), the catalogue's stats snapshot (11), badge rows (1), medal facts (1); the
        // rarity aggregate is cached in production (here its two cache entries miss once each: +2)
        $medalReads = array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'FROM badge mine'));
        self::assertCount(1, $medalReads);
        $this->assertQueryCountAtMost($browser, 20, 'achievement sheet (own medal)');
    }

    public function testTheTappedTierIsShownGreyWithoutABackWhenNotEarned(): void
    {
        $browser = self::createClient();
        $this->giveMemberGoldAfterLaunch();
        TestingLogin::asPlayer($browser, self::MEMBER);

        $crawler = $this->sheet($browser, '?tier=5');

        self::assertStringContainsString('/puzzles_solved-t5.webp', (string) $crawler->filter('img.medal-art')->attr('src'));
        self::assertStringContainsString('badge-art-locked', (string) $crawler->filter('img.medal-art')->attr('class'));
        self::assertNotNull($crawler->filter('.medal button.medal-card')->attr('disabled'));
        self::assertStringContainsString('Not earned yet', $crawler->filter('.medal-facts-tier[data-tier="5"]')->text());
    }

    public function testAVisitorSeesTheMembersMedalFromTheirPage(): void
    {
        $browser = self::createClient();
        $this->giveMemberGoldAfterLaunch();
        // A free player: loses only their own ticks, never the subject's medal (design-spec §8.3)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $crawler = $this->sheet($browser, '?player=' . self::MEMBER);

        self::assertSelectorTextContains('.achievement-sheet-whose', PlayerFixture::PLAYER_WITH_STRIPE_NAME);
        self::assertSame('medal-back-3', $crawler->filter('.medal button.medal-card')->attr('aria-describedby'));
        self::assertStringContainsString('No. 0001', $crawler->filter('#medal-back-3')->text());
        self::assertCount(3, $crawler->filter('li.medal-tier .medal-tier-check'));
        // Someone else's progress toward the next tier is on show too (Jan 2026-10-11)
        self::assertCount(1, $crawler->filter('.medal-tier-progress'));
        self::assertCount(0, $crawler->filter('a[href="/en/membership"]'));
        // + the subject's profile row (GetPlayerProfile::byId: privacy, blocklist, membership)
        $this->assertQueryCountAtMost($browser, 21, 'achievement sheet (another member\'s medal)');
    }

    public function testAPrivateMembersMedalIsIgnoredSilently(): void
    {
        $browser = self::createClient();
        $this->giveMemberGoldAfterLaunch();
        $this->connection()->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => self::MEMBER]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->sheet($browser, '?player=' . self::MEMBER);

        $this->assertTheBareTiers($crawler);
    }

    public function testABlockedMembersMedalIsIgnoredSilently(): void
    {
        $browser = self::createClient();
        $this->giveMemberGoldAfterLaunch();
        $this->connection()->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_REGULAR, 'blocked' => self::MEMBER],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->sheet($browser, '?player=' . self::MEMBER);

        $this->assertTheBareTiers($crawler);
    }

    public function testANonMembersAchievementsAreNotShownSoTheViewerGetsTheirOwn(): void
    {
        $browser = self::createClient();
        $this->giveMemberGoldAfterLaunch();
        TestingLogin::asPlayer($browser, self::MEMBER);

        $crawler = $this->sheet($browser, '?player=' . PlayerFixture::PLAYER_REGULAR);

        self::assertCount(0, $crawler->filter('.achievement-sheet-whose'));
        self::assertSame('medal-back-3', $crawler->filter('.medal button.medal-card')->attr('aria-describedby'));
    }

    public function testAGuestGetsBronzeWithoutABackAndTheTease(): void
    {
        $browser = self::createClient();

        $crawler = $this->sheet($browser);

        $this->assertTheBareTiers($crawler);
        self::assertStringContainsString('/puzzles_solved-t1.webp', (string) $crawler->filter('img.medal-art')->attr('src'));
        self::assertStringNotContainsString('badge-art-locked', (string) $crawler->filter('img.medal-art')->attr('class'));
    }

    private function assertTheBareTiers(Crawler $crawler): void
    {
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.medal button.medal-card'));
        self::assertCount(0, $crawler->filter('.medal-engraving'));
        self::assertCount(0, $crawler->filter('.achievement-sheet-whose'));
        self::assertCount(5, $crawler->filter('li.medal-tier'));
        self::assertCount(0, $crawler->filter('li.medal-tier .medal-tier-check'));
        self::assertCount(0, $crawler->filter('.medal-tier-ap'));
        self::assertCount(1, $crawler->filter('a[href="/en/membership"][data-turbo-frame="_top"]'));
    }

    private function sheet(KernelBrowser $browser, string $query = ''): Crawler
    {
        $crawler = $browser->request('GET', self::SHEET . $query, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * Bronze and Silver held at launch (the backfill), Gold earned afterwards - the only Gold there is.
     */
    private function giveMemberGoldAfterLaunch(): void
    {
        $this->connection()->executeStatement('DELETE FROM badge WHERE type = :type', ['type' => self::TYPE]);

        foreach ([1 => '2026-08-01 23:00:00', 2 => '2026-08-01 23:00:00', 3 => '2026-09-14 18:00:00'] as $tier => $earnedAt) {
            $this->connection()->executeStatement(
                'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, :type, :earnedAt, :tier, :earnedAt)',
                ['id' => Uuid::uuid7()->toString(), 'player' => self::MEMBER, 'type' => self::TYPE, 'earnedAt' => $earnedAt, 'tier' => $tier],
            );
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    public function testTheSecondButtonDependsOnWhereTheSheetWasOpened(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $frame = ['HTTP_TURBO_FRAME' => 'modal-frame'];

        // From a shelf (catalogue, a player's achievements page): the full list is the page behind - no second button
        $crawler = $browser->request('GET', '/en/achievements/puzzles_solved/info?from=shelf', server: $frame);
        self::assertCount(1, $crawler->filter('.medal-actions a'));

        // Elsewhere, the viewer's own medal: their own achievements
        $crawler = $browser->request('GET', '/en/achievements/puzzles_solved/info', server: $frame);
        self::assertSame('/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE, $crawler->filter('.medal-actions a.btn-outline-secondary')->attr('href'));
        self::assertSame('My achievements', trim($crawler->filter('.medal-actions a.btn-outline-secondary')->text()));

        // Another member's medal (e.g. from their profile showcase): that member's achievements
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/achievements/puzzles_solved/info?player=' . PlayerFixture::PLAYER_WITH_STRIPE, server: $frame);
        self::assertSame('/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE, $crawler->filter('.medal-actions a.btn-outline-secondary')->attr('href'));
        self::assertStringEndsWith("'s achievements", trim($crawler->filter('.medal-actions a.btn-outline-secondary')->text()));

        // A non-member on their own: the catalogue
        $crawler = $browser->request('GET', '/en/achievements/puzzles_solved/info', server: $frame);
        self::assertSame('/en/achievements', $crawler->filter('.medal-actions a.btn-outline-secondary')->attr('href'));
    }
}
