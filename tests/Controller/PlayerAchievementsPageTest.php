<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A player's achievements page - the "Achievements" tab of the player pages (PlayerAchievementsController,
 * docs/features/player-header.md). Only members have achievements; a non-member owner gets the member tease.
 */
final class PlayerAchievementsPageTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string MEMBER_PAGE = '/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE;

    public function testOwnerSeesTheirAchievementsWithTheRevealForAnUnrevealedTier(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'streak', 1, revealed: false);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.player-tab.active', 'Achievements');
        self::assertSelectorTextContains('.achievements-summary', '2 of 16 earned');
        self::assertSelectorExists('.achievements-summary .ap-chip');
        self::assertCount(2, $crawler->filter('.achievement-row'));
        // The unrevealed tier is a "?" leading to the reveal page, the revealed one opens the info modal
        self::assertCount(1, $crawler->filter('a.achievement-row[href="/en/my/achievement-reveals"] .badge-art-mystery'));
        self::assertCount(1, $crawler->filter('a.achievement-row[href^="/en/achievements/puzzles_solved/info"]'));
        self::assertSelectorExists('a[href="/en/achievements?show=all"]');

        // One read of the player's badge rows feeds the whole list
        $badgeReads = array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'FROM badge') && str_contains($sql, 'player_id = '));
        self::assertCount(1, $badgeReads);
        $this->assertQueryCountAtMost($browser, 35, 'player achievements page (owner)');
    }

    public function testVisitorsSeeAMembersAchievementsWithoutTheReveal(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'streak', 1, revealed: false);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.achievement-row'));
        self::assertSelectorNotExists('.badge-art-mystery');
    }

    public function testGuestsSeeAMembersAchievements(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);

        $browser->request('GET', self::MEMBER_PAGE . '?show=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievements-filter a.active', 'All');
        self::assertGreaterThanOrEqual(16, $browser->getCrawler()->filter('.achievement-row')->count());
    }

    public function testFreeOwnerGetsTheTabWithTheMemberTease(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-achievements/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.player-tab.active', 'Achievements');
        self::assertSelectorExists('a.btn[href="/en/membership"]');
        self::assertCount(0, $crawler->filter('.achievement-row'));
    }

    public function testANonMembersAchievementsAreNotThereForOthers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/player-achievements/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseRedirects('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.player-tab[href^="/en/player-achievements/"]'));
    }

    public function testAPrivateMembersAchievementsAreForTheOwnerOnly(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseRedirects('/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
    }

    public function testBeforeLaunchThePageAndTheTabAreNotThere(): void
    {
        FixedXpLaunchReadiness::$ready = false;
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::MEMBER_PAGE);
        self::assertResponseStatusCodeSame(404);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.player-tab[href^="/en/player-achievements/"]'));
    }

    public function testTheShowcaseAndTheUserMenuLinkTheOwnPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.achievements-showcase-summary[href="' . self::MEMBER_PAGE . '"]'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('.dropdown-menu a[href="' . self::MEMBER_PAGE . '"]')->count());
    }

    private function insertBadge(string $playerId, string $type, int $tier, bool $revealed): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :playerId, :type, NOW(), :tier, :revealedAt)',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'type' => $type, 'tier' => $tier, 'revealedAt' => $revealed ? '2026-10-01 10:00:00' : null],
        );
    }
}
