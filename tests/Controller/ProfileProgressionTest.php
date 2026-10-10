<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Progression in the player header and the navbar (docs/features/xp-levels/launch-2026-10.md, workstream C): the level
 * ring around the header avatar (no second avatar), the chip row (skill tier, MSP Rating place), the navbar avatar with
 * its ring - all read from profile rows, no query of their own. The rings follow XpFeatureGate (launch ready or admin):
 * the visible cases are seen by an admin, the hidden ones by everybody else.
 */
final class ProfileProgressionTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testTheHeaderAvatarWearsTheLevelRingAndThereIsNoSecondAvatar(): void
    {
        $browser = self::createClient();
        $this->setXp(PlayerFixture::PLAYER_WITH_STRIPE, LevelTable::xpForLevel(12) + 1);

        $crawler = $this->page($browser, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE);

        $ring = $crawler->filter('.player-head-avatar a.xp-avatar-ring.xp-avatar-ring-header.xp-ring-milestone-10');
        self::assertCount(1, $ring);
        self::assertSame('/en/how-xp-works', $ring->attr('href'));
        self::assertStringStartsWith('Level 12, ', (string) $ring->attr('aria-label'));
        self::assertMatchesRegularExpression('/^--xp-progress: 0\.\d+$/', (string) $ring->attr('style'));
        self::assertSame('Lv 12', trim($ring->filter('.xp-level-tab')->text()));
        self::assertCount(1, $ring->filter('.lb-avatar-xl'));

        // The compact bar keeps the ring, without the tab
        self::assertCount(1, $crawler->filter('.player-bar .xp-avatar-ring-bar .lb-avatar-lg'));
        self::assertCount(0, $crawler->filter('.player-bar .xp-level-tab'));

        // One avatar of this size on the page: the old XP block with its own 52 px avatar is gone
        self::assertCount(1, $crawler->filter('.lb-avatar-xl'));
        self::assertCount(0, $crawler->filter('.xp-progressbar'));
    }

    public function testTheOwnerRingLeadsToTheirXpHistoryAndLevelFiftyIsGold(): void
    {
        $browser = self::createClient();
        $this->setXp(PlayerFixture::PLAYER_ADMIN, LevelTable::xpForLevel(LevelTable::MAX_LEVEL));

        $crawler = $this->page($browser, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_ADMIN);

        $ring = $crawler->filter('.player-head-avatar a.xp-avatar-ring');
        self::assertSame('/en/my/xp-history', $ring->attr('href'));
        self::assertStringContainsString('xp-ring-milestone-50', (string) $ring->attr('class'));
        self::assertSame('--xp-progress: 1', $ring->attr('style'));
        self::assertCount(1, $ring->filter('.xp-level-tab.xp-level-tab-max'));
    }

    public function testNoRingForAnOptedOutPlayerAndTheAvatarStaysAlone(): void
    {
        $browser = self::createClient();
        $this->database()->executeStatement('UPDATE player SET experience_system_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);

        $crawler = $this->page($browser, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertCount(0, $crawler->filter('.player-header .xp-avatar-ring'));
        self::assertCount(1, $crawler->filter('.player-head-avatar > .lb-avatar-xl'));
        self::assertCount(1, $crawler->filter('.lb-avatar-xl'));
    }

    public function testAHiddenPrivateProfileHasNoRingAndNoStrayAvatar(): void
    {
        $browser = self::createClient();

        // An admin who is not on the allow list: the profile is hidden from them
        $crawler = $this->page($browser, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE);

        self::assertCount(0, $crawler->filter('.player-header .xp-avatar-ring'));
        self::assertCount(1, $crawler->filter('.lb-avatar-xl .lb-avatar-private'));
        self::assertCount(1, $crawler->filter('.lb-avatar-xl'));

        // The owner sees their own ring on their private profile (made an admin to see past the gate)
        $this->database()->executeStatement('UPDATE player SET is_admin = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);
        $crawler = $this->page($browser, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_PRIVATE);
        self::assertCount(1, $crawler->filter('.player-head-avatar .xp-avatar-ring'));
    }

    public function testTheMspRatingChipShowsThePlaceAndLinksTheLadder(): void
    {
        $browser = self::createClient();
        $this->rate(PlayerFixture::PLAYER_WITH_STRIPE, 0.9);
        $this->rate(PlayerFixture::PLAYER_REGULAR, 0.95);

        $crawler = $this->page($browser, null, PlayerFixture::PLAYER_WITH_STRIPE);

        $chip = $crawler->filter('.player-head-chips a.player-chip-competition');
        self::assertCount(1, $chip);
        self::assertSame('#2 MSP Rating', trim($chip->text()));
        self::assertSame('/en/msp-rating', $chip->attr('href'));
        // A free visitor sees one lock at the top of the profile: the header's skill-tier chip - the rating card below
        // carries no "Player Insights" lock any more
        self::assertCount(1, $crawler->filter('.player-header [data-bs-target="#membersExclusiveModal"]'));
        self::assertCount(1, $crawler->filter('.player-head-chips [data-bs-target="#membersExclusiveModal"]'));
        self::assertCount(0, $crawler->filter('.profile-standing [data-bs-target="#membersExclusiveModal"]'));

        // At most three chips; out of the ladder = no place
        self::assertLessThanOrEqual(3, $crawler->filter('.player-head-chips > li')->count());
        $this->database()->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        $crawler = $this->page($browser, null, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertCount(0, $crawler->filter('.player-chip-competition'));
    }

    public function testTheNavbarShowsTheViewersAvatarWithTheirRingAndRunsNoXpQuery(): void
    {
        $browser = self::createClient();
        $this->setXp(PlayerFixture::PLAYER_ADMIN, LevelTable::xpForLevel(3) + 1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/faq');
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $box = $crawler->filter('.navbar-tool.dropdown .navbar-avatar-box');
        self::assertCount(1, $box);
        $ring = $box->filter('.xp-avatar-ring.xp-avatar-ring-nav');
        self::assertCount(1, $ring);
        self::assertNotSame('--xp-progress: 1', $ring->attr('style'), 'The ring shows progress, not an always-full circle');
        // No photo: the initial on the player's tint, like in the leaderboard rows
        self::assertSame('A', trim($ring->filter('.lb-avatar-initial')->text()));
        self::assertCount(0, $crawler->filter('.navbar-tool .ci-user'));

        // The level rides on the viewer's own profile row: the only statement reading xp_total is that row
        $xpReads = array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'xp_total'));
        self::assertCount(1, $xpReads);
        self::assertStringContainsString('WHERE player.user_id = ?', (string) array_values($xpReads)[0]);
    }

    public function testWhileXpIsNotVisibleTheAvatarsStayPlainAndAlone(): void
    {
        // Before the XP launch (the switch off): only admins see progression
        FixedXpLaunchReadiness::$ready = false;
        $browser = self::createClient();
        $this->setXp(PlayerFixture::PLAYER_WITH_STRIPE, LevelTable::xpForLevel(12) + 1);
        $this->earnStreak(PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ([null, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE] as $viewer) {
            $crawler = $this->page($browser, $viewer, PlayerFixture::PLAYER_WITH_STRIPE);
            $who = $viewer ?? 'guest';

            self::assertCount(0, $crawler->filter('.xp-avatar-ring, .xp-level-tab'), $who);
            self::assertCount(1, $crawler->filter('.player-head-avatar > .lb-avatar-xl'), $who);
            self::assertCount(1, $crawler->filter('.lb-avatar-xl'), $who . ': no stray second avatar');
            self::assertCount(0, $crawler->filter('.achievements-showcase, .achievements-teaser'), $who);
        }

        // The navbar keeps the avatar (or initial), only without the ring
        $crawler = $this->page($browser, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertCount(1, $crawler->filter('.navbar-avatar-box > .lb-avatar'));
    }

    public function testTheProfilePageCost(): void
    {
        $browser = self::createClient();

        // Guest on a member's profile: the profile row (header chips + ring included), the page's own statements and,
        // since the XP launch, the member's achievements showcase (page content, one statement)
        self::assertSame(11, $this->measure($browser, null, PlayerFixture::PLAYER_WITH_STRIPE));
        // A member viewer: + the signed-in overhead, the achievements showcase and the skill / rating cards; the
        // header's tier and the rings cost nothing
        self::assertSame(22, $this->measure($browser, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    private function measure(KernelBrowser $browser, null|string $viewer, string $playerId): int
    {
        $this->page($browser, $viewer, $playerId);
        $this->startCountingQueries($browser);
        $this->page($browser, $viewer, $playerId);

        return $this->queryCount($browser);
    }

    private function page(KernelBrowser $browser, null|string $viewer, string $playerId): Crawler
    {
        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        } else {
            $browser->getCookieJar()->clear();
        }

        $crawler = $browser->request('GET', '/en/player-profile/' . $playerId);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function setXp(string $playerId, int $xp): void
    {
        $this->database()->executeStatement(
            'UPDATE player SET xp_total = :xp, level = :level WHERE id = :id',
            ['xp' => $xp, 'level' => LevelTable::levelForXp($xp), 'id' => $playerId],
        );
    }

    private function rate(string $playerId, float $rating): void
    {
        $this->database()->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :rating, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'rating' => $rating],
        );
    }

    private function earnStreak(string $playerId): void
    {
        $this->database()->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, 'streak', NOW(), 1, NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId],
        );
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
