<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The holders page of an achievement (AchievementDetailController, docs/features/xp-levels/ux-polish/design-spec.md §1
 * "Holders page", amended by §8.1/§8.2): holders newest first with their date, the launch backfill's last and "at
 * launch", "First to earn" only after launch, the member viewer's medal number in the hero, and the same number of
 * statements whatever the number of holders.
 */
final class AchievementHoldersPageTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string PAGE = '/en/achievements/zen_puzzler';

    /** Before the tests' launch marker (XpSettingsFixture::LAUNCH_READY_AT): stamped by the launch backfill */
    private const string AT_LAUNCH = '2026-07-15 10:00:00';

    public function testHoldersAreListedNewestFirstAndTheLaunchHoldersLastWithoutADate(): void
    {
        $browser = self::createClient();
        $this->earn(PlayerFixture::PLAYER_ADMIN, self::AT_LAUNCH);
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, '2026-09-05 10:00:00');
        $this->seedListedHolders(1, '2026-09-10 10:00:00');

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('#tier-1 .holder-row');
        self::assertSame(['Zen Holder 1', 'Sarah Williams', 'Admin User'], $this->names($rows));
        self::assertSame('2026-09-10', $rows->eq(0)->filter('time')->attr('datetime'));
        self::assertSame('at launch', trim($rows->eq(2)->filter('.holder-row-date')->text()));
        self::assertCount(0, $rows->eq(2)->filter('time'));
        // No number column; the first holder earned it at launch, so nobody is "First to earn"
        self::assertSelectorNotExists('.holder-row-no');
        self::assertSelectorNotExists('.holder-row-trophy');
        // A guest holds nothing: no hero line
        self::assertSelectorNotExists('.achievement-hero-mine');
    }

    public function testFirstToEarnIsTheFirstHolderAfterLaunchAndUnlistedHoldersAreCounted(): void
    {
        $browser = self::createClient();
        $this->earn(PlayerFixture::PLAYER_ADMIN, '2026-09-01 10:00:00');
        $this->earn(PlayerFixture::PLAYER_REGULAR, '2026-09-02 10:00:00'); // not a member: never listed
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, '2026-09-03 10:00:00');

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('#tier-1 .holder-row');
        self::assertCount(2, $rows);
        self::assertCount(0, $rows->eq(0)->filter('.holder-row-trophy'));
        self::assertCount(1, $rows->eq(1)->filter('.holder-row-trophy'));
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_ADMIN, $rows->eq(1)->filter('a')->attr('href'));
        self::assertStringContainsString('3 puzzlers', $crawler->filter('#tier-1 h2')->text());
        // Counted but never listed: said as such, not as a list one could open
        self::assertStringContainsString('+1 puzzler whose profile is not listed', $crawler->filter('#tier-1')->text());
        self::assertSelectorNotExists('#tier-1 .holder-more-button');
    }

    public function testAnUnlistedFirstHolderLeavesNobodyWithTheTrophy(): void
    {
        $browser = self::createClient();
        $this->earn(PlayerFixture::PLAYER_PRIVATE, '2026-09-01 10:00:00'); // private profile: never listed
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, '2026-09-03 10:00:00');

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '#tier-1 .holder-row');
        self::assertSelectorNotExists('.holder-row-trophy');
    }

    public function testTheLadderCountsEveryTierAndLinksToItsSection(): void
    {
        $browser = self::createClient();
        $this->earn(PlayerFixture::PLAYER_ADMIN, '2026-09-01 10:00:00', tier: 1);
        $this->earn(PlayerFixture::PLAYER_ADMIN, '2026-09-05 10:00:00', tier: 2);
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, '2026-09-02 10:00:00', tier: 1);

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $steps = $crawler->filter('.achievement-ladder a');
        self::assertSame(['#tier-5', '#tier-4', '#tier-3', '#tier-2', '#tier-1'], $steps->each(static fn (Crawler $step): string => (string) $step->attr('href')));
        // A jump within the page: never a Turbo visit
        self::assertSame(['false', 'false', 'false', 'false', 'false'], $steps->each(static fn (Crawler $step): string => (string) $step->attr('data-turbo')));
        self::assertSame(['0', '0', '0', '1', '2'], $crawler->filter('.achievement-ladder-count')->each(static fn (Crawler $count): string => trim($count->text())));
        self::assertCount(5, $crawler->filter('section.achievement-tier-section[id^="tier-"]'));
        self::assertStringContainsString('Nobody has earned this tier yet', $crawler->filter('#tier-5')->text());
    }

    public function testAMemberSeesTheirTierAndMedalNumberInTheHero(): void
    {
        $browser = self::createClient();
        $this->earn(PlayerFixture::PLAYER_PRIVATE, '2026-09-01 10:00:00');
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, '2026-09-02 10:00:00');
        $this->earn(PlayerFixture::PLAYER_ADMIN, '2026-09-03 10:00:00');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        // The medal number counts every holder ahead, listed or not
        self::assertSelectorTextContains('.achievement-hero-mine', 'You hold Bronze · No. 2 of 3');
        self::assertSelectorExists('.achievement-ladder-step.is-mine[href="#tier-1"]');
        // No pinned own row: the list is the list
        self::assertCount(2, $crawler->filter('#tier-1 .holder-row'));
    }

    public function testAMemberWhoHeldItAtLaunchGetsNoNumber(): void
    {
        $browser = self::createClient();
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, self::AT_LAUNCH);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievement-hero-mine', 'You hold Bronze · held at launch');
        self::assertSelectorTextNotContains('.achievement-hero-mine', 'No.');
    }

    public function testABlockedHolderIsLeftOut(): void
    {
        $browser = self::createClient();
        $this->earn(PlayerFixture::PLAYER_ADMIN, '2026-09-01 10:00:00');
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, '2026-09-02 10:00:00');
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_STRIPE, 'blocked' => PlayerFixture::PLAYER_ADMIN],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/en/player-profile/' . PlayerFixture::PLAYER_ADMIN . '"]'));
        self::assertCount(1, $crawler->filter('#tier-1 .holder-row'));
        // The medal number still counts the blocked holder ahead
        self::assertSelectorTextContains('.achievement-hero-mine', 'No. 2 of 2');
    }

    public function testTheCountryFilterNarrowsTheList(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE player SET country = 'cz' WHERE id = :id", ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        $database->executeStatement("UPDATE player SET country = 'de' WHERE id = :id", ['id' => PlayerFixture::PLAYER_ADMIN]);
        $this->earn(PlayerFixture::PLAYER_ADMIN, '2026-09-01 10:00:00');
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, '2026-09-02 10:00:00');

        $crawler = $browser->request('GET', self::PAGE . '?country=cz');

        self::assertResponseIsSuccessful();
        self::assertSame(['Sarah Williams'], $this->names($crawler->filter('#tier-1 .holder-row')));
        self::assertSame('1', trim($crawler->filter('.achievement-ladder-count')->last()->text()));
        $options = $crawler->filter('#holder-country option')->each(static fn (Crawler $option): string => $option->text());
        self::assertContains('Czechia', $options);
    }

    public function testTheHoldersPageSitsUnderTheAchievementsCatalogue(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $trail = $crawler->filter('.page-crumbs-trail a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertSame(['/en/how-progress-works', '/en/achievements'], $trail);
        self::assertSelectorNotExists('ol.breadcrumb');
        $jsonLd = implode("\n", $crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $node): string => $node->text()));
        self::assertStringContainsString('BreadcrumbList', $jsonLd);
    }

    public function testEveryListedHolderCanBeShownInPlace(): void
    {
        $browser = self::createClient();
        $this->seedListedHolders(45);

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(30, $crawler->filter('#tier-1 .holder-row'));
        // 15 more listed: one "Show all 45" (no "Show 100 more" below 100), loading only this tier's frame
        $buttons = $crawler->filter('#tier-1 turbo-frame#holders-tier-1 .holder-more-button');
        self::assertCount(1, $buttons);
        self::assertSame('Show all 45', trim($buttons->text()));
        self::assertSame('holders-tier-1', $buttons->attr('data-turbo-frame'));
        self::assertSame('nofollow', $buttons->attr('rel'));

        $crawler = $browser->request('GET', (string) $buttons->attr('href'), server: ['HTTP_TURBO_FRAME' => 'holders-tier-1']);

        self::assertResponseIsSuccessful();
        self::assertCount(45, $crawler->filter('#holders-tier-1 .holder-row'));
        self::assertSelectorNotExists('#holders-tier-1 .holder-more-button');
        self::assertSame('noindex, follow', $browser->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testThePageCostsTheSameWhateverTheNumberOfHolders(): void
    {
        $browser = self::createClient();
        $this->earn(PlayerFixture::PLAYER_WITH_STRIPE, '2026-09-01 10:00:00');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        // The first request of a session also loads the session and the profile - compare like with like
        $browser->request('GET', self::PAGE);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        $few = $this->queryCount($browser);

        $this->seedListedHolders(45);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        $many = $this->queryCount($browser);

        self::assertSame($few, $many, 'the holders page must not run a statement per holder');
        // Listed rows, counts, countries and the viewer's medal (GetAchievementMedal) - nothing else reads badge rows
        $badgeStatements = array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'FROM badge'));
        self::assertCount(4, $badgeStatements);
        $this->assertQueryCountAtMost($browser, 8, 'achievement holders page (member)');
    }

    public function testAGuestPaysTheSameBudget(): void
    {
        $browser = self::createClient();
        $this->seedListedHolders(5);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 3, 'achievement holders page (guest): the three holder statements, nothing else');
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $rows): array
    {
        return $rows->each(static fn (Crawler $row): string => trim($row->filter('.holder-row-name')->text()));
    }

    private function earn(string $playerId, string $earnedAt, int $tier = 1): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :playerId, 'zen_puzzler', :earnedAt, :tier, :earnedAt)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'earnedAt' => $earnedAt, 'tier' => $tier],
        );
    }

    /**
     * Listed holders (public members) of the Bronze tier, one an hour from $from. Plain SQL: made-up players, rolled
     * back with the test.
     */
    private function seedListedHolders(int $count, string $from = '2026-09-15 10:00:00'): void
    {
        $database = self::getContainer()->get(Connection::class);

        $database->executeStatement(
            <<<SQL
INSERT INTO player (id, code, name, registered_at)
SELECT gen_random_uuid(), 'zenholder' || n, 'Zen Holder ' || n, NOW() - INTERVAL '1 year'
FROM generate_series(1, :count) AS n
SQL,
            ['count' => $count],
            ['count' => ParameterType::INTEGER],
        );

        $database->executeStatement(
            <<<SQL
INSERT INTO membership (id, player_id, created_at, granted_until)
SELECT gen_random_uuid(), player.id, NOW(), NOW() + INTERVAL '30 days'
FROM generate_series(1, :count) AS n
INNER JOIN player ON player.code = 'zenholder' || n
SQL,
            ['count' => $count],
            ['count' => ParameterType::INTEGER],
        );

        $database->executeStatement(
            <<<SQL
INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at)
SELECT gen_random_uuid(), player.id, 'zen_puzzler', CAST(:from AS TIMESTAMP) + (n - 1) * INTERVAL '1 hour', 1, NOW()
FROM generate_series(1, :count) AS n
INNER JOIN player ON player.code = 'zenholder' || n
SQL,
            ['count' => $count, 'from' => $from],
            ['count' => ParameterType::INTEGER],
        );
    }
}
