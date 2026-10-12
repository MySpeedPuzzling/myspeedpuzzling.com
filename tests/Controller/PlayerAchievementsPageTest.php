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
        // Types and tiers, counted from the catalogue the page already loaded
        self::assertSelectorTextContains('.achievements-summary', '2 of 16 achievements · 2 of 80 tiers');
        self::assertSelectorExists('.achievements-summary .ap-chip');
        // Default = All: every type on the shelf, the unrevealed one (an empty puzzle) first (it is an action), then the earned one
        self::assertSelectorTextContains('.achievements-filter a.active', 'All · 16');
        $cells = $crawler->filter('.achievement-shelf > .achievement-cell');
        self::assertCount(16, $cells);
        self::assertCount(1, $cells->eq(0)->filter('a[href="/en/my/achievement-reveals"] .achievement-cell-tray'));
        self::assertStringContainsString('New tier · tap to reveal', $cells->eq(0)->text());
        self::assertSame(
            // + where Back goes from the sheet's buttons: this page (docs/features/return-url.md)
            '/en/achievements/puzzles_solved/info?tier=1&player=' . PlayerFixture::PLAYER_WITH_STRIPE . '&from=shelf&return=' . self::MEMBER_PAGE . '&return_title=My%20achievements',
            $cells->eq(1)->filter('a')->attr('href'),
        );
        // Earned today: the owner's "New" dot; the picture of an unrevealed tier is not in the page
        self::assertCount(1, $cells->eq(1)->filter('.achievement-cell-new'));
        self::assertCount(0, $crawler->filter('.achievement-shelf img[src*="streak-t1"]'));
        self::assertSelectorExists('a[href="/en/achievements?show=all"]');

        // One read of the player's badge rows feeds the whole page
        $badgeReads = array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'FROM badge') && str_contains($sql, 'player_id = '));
        self::assertCount(1, $badgeReads);
        $this->assertQueryCountAtMost($browser, 35, 'player achievements page (owner)');
    }

    public function testVisitorsSeeAMembersAchievementsWithoutTheRevealAndWithTheirMedals(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'streak', 1, revealed: false);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::MEMBER_PAGE . '?show=earned');

        self::assertResponseIsSuccessful();
        // A visitor gets one list, rarest first: what the player holds leads - no filter chips (?show is ignored)
        self::assertSelectorNotExists('.achievements-filter');
        $cells = $crawler->filter('.achievement-cell');
        self::assertCount(16, $cells);
        self::assertSelectorNotExists('.achievement-cell-tray');
        // Not the visitor's own page: no "New" dot; the sheet opens on this player's medal
        self::assertSelectorNotExists('.achievement-cell-new');
        self::assertStringStartsWith('/en/achievements/streak/info?tier=1&player=' . PlayerFixture::PLAYER_WITH_STRIPE . '&from=shelf&return=' . self::MEMBER_PAGE, (string) $cells->first()->filter('a')->attr('href'));
    }

    public function testGuestsSeeAMembersAchievements(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);

        $browser->request('GET', self::MEMBER_PAGE . '?show=all');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.achievements-filter');
        self::assertGreaterThanOrEqual(16, $browser->getCrawler()->filter('.achievement-cell')->count());
    }

    public function testTheFiltersAreAllEarnedNextUpAndRarestWithAllAsTheDefault(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::MEMBER_PAGE . '?show=nonsense');

        self::assertResponseIsSuccessful();
        $filters = $crawler->filter('.achievements-filter a')->each(static fn ($node): string => trim($node->text()));
        self::assertCount(4, $filters);
        self::assertSame('All · 16', $filters[0]);
        self::assertSame('Earned · 1', $filters[1]);
        self::assertStringStartsWith('Next up', $filters[2]);
        self::assertSame('Rarest first · 16', $filters[3]);
        self::assertSelectorTextContains('.achievements-filter a.active', 'All');
    }

    public function testClosestNextStepsAreTheOwnersOnly(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        $steps = $crawler->filter('.achievements-next .achievements-next-row');
        self::assertGreaterThanOrEqual(1, $steps->count());
        self::assertLessThanOrEqual(3, $steps->count());
        self::assertSelectorTextContains('.achievements-next-title', 'Closest next steps');
        self::assertStringContainsString('&player=' . PlayerFixture::PLAYER_WITH_STRIPE, (string) $steps->first()->attr('href'));
        self::assertCount(1, $steps->first()->filter('img.badge-art-locked[width="40"]'));
        // Only these bars fill when seen; the shelf's are static
        self::assertGreaterThanOrEqual(1, $crawler->filter('.achievements-next [data-progress-fill-target="bar"]')->count());
        self::assertCount(0, $crawler->filter('.achievement-shelf [data-progress-fill-target]'));

        // A visitor came to see what she has, not what she is missing (design-spec §8.6)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::MEMBER_PAGE);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.achievements-next');
    }

    public function testAVisitorSeesNoStreakNumbersWhenTheOwnerSwitchedStreaksOff(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'streak', 1, revealed: true);
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET streak_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        $streak = $crawler->filter('.achievement-cell a[href^="/en/achievements/streak/info"]');
        self::assertCount(1, $streak);
        self::assertStringContainsString('Bronze', $streak->text());
        self::assertCount(0, $streak->filter('[role="progressbar"], .achievement-cell-numbers'));
        self::assertSame('On Fire, Bronze, tier 1 of 5, Solve puzzles 7 days in a row', $streak->attr('aria-label'));
    }

    public function testCellsHaveAnAccessibleNameWithEverythingTheyShow(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 2, revealed: true);

        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        // A visitor sees the progress too (Jan 2026-10-11): the tier, the next goal and how far along
        $label = (string) $crawler->filter('.achievement-cell a[href^="/en/achievements/puzzles_solved/info"]')->attr('aria-label');
        self::assertStringStartsWith('Puzzle Explorer, Silver, tier 2 of 5, Gold: Solve 500 distinct puzzles', $label);
        self::assertStringContainsString(' toward Gold', $label);
        self::assertGreaterThan(0, $crawler->filter('.achievement-shelf [role="progressbar"]')->count());
        self::assertSelectorNotExists('.achievements-filter');

        // The owner: the same, with their filter chips
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', self::MEMBER_PAGE);
        $label = (string) $crawler->filter('.achievement-cell a[href^="/en/achievements/puzzles_solved/info"]')->attr('aria-label');
        self::assertStringStartsWith('Puzzle Explorer, Silver, tier 2 of 5, ', $label);
        self::assertStringContainsString(' toward Gold', $label);
    }

    public function testAnOwnerWithoutAchievementsGetsTheirFirstOneAsTheNextStep(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievements-next-title', 'Your first one is close');
        self::assertCount(1, $crawler->filter('.achievements-next-row'));
        self::assertStringContainsString('→ Bronze', $crawler->filter('.achievements-next-row')->text());
    }

    public function testFreeOwnerGetsTheTabWithTheMemberTease(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-achievements/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.player-tab.active', 'Achievements');
        self::assertSelectorExists('a.btn[href="/en/membership"]');
        self::assertCount(0, $crawler->filter('.achievement-cell'));
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
        // Its Back leads to the profile again (docs/features/return-url.md)
        self::assertCount(1, $crawler->filter('.achievements-showcase-summary[href^="' . self::MEMBER_PAGE . '?return=/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE . '&"]'));
        // The Menu's Achievements row (MENU-01)
        self::assertCount(1, $crawler->filter('#site-menu a[href="' . self::MEMBER_PAGE . '"]'));
    }

    public function testAHeldEarlyAdopterCountsAsAnAchievement(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'supporter', null, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        // Early Adopter can no longer be earned: one more achievement for its holders, in the earned count and the total
        self::assertSelectorTextContains('.achievements-summary', '2 of 17 achievements');
        self::assertSelectorTextContains('.achievements-summary', '1 of 80 tiers');
        self::assertCount(1, $crawler->filter('.achievement-shelf a[href^="/en/achievements/supporter/info"]'));
        // What it means to its holder, not our word for it ("One-off")
        self::assertStringContainsString('believed in MySpeedPuzzling early', $crawler->filter('.achievement-shelf a[href^="/en/achievements/supporter/info"]')->text());
        $filters = $crawler->filter('.achievements-filter a')->each(static fn ($node): string => trim($node->text()));
        self::assertSame('All · 17', $filters[0]);
        self::assertSame('Earned · 2', $filters[1]);
    }

    public function testWithoutEarlyAdopterTheTotalIsTheSixteenThatCanBeEarned(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievements-summary', '1 of 16 achievements');
    }

    public function testEveryCellSaysWhatToDoNext(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        // A tier held: the next tier's requirement, named
        $held = $crawler->filter('.achievement-cell a[href^="/en/achievements/puzzles_solved/info"]');
        self::assertSame('Silver: Solve 100 distinct puzzles', trim($held->filter('.achievement-cell-goal')->text()));
        // Nothing held yet: the Bronze requirement alone - never a bare "Bronze next"
        $none = $crawler->filter('.achievement-cell a[href^="/en/achievements/brand_explorer/info"]');
        self::assertSame('Solve puzzles from 5 different manufacturers', trim($none->filter('.achievement-cell-goal')->text()));
        self::assertCount(0, $none->filter('.achievement-cell-tier'));
        // Every cell that is not complete has its goal
        self::assertCount($crawler->filter('.achievement-shelf .achievement-cell-link')->count(), $crawler->filter('.achievement-shelf .achievement-cell-goal'));
    }

    public function testAVisitorSeesEarlyAdopterFirstThenTheHighestTiersTheRarestFirstWithinATier(): void
    {
        $browser = self::createClient();
        $member = PlayerFixture::PLAYER_WITH_STRIPE;
        $this->insertBadge($member, 'supporter', null, revealed: true);
        $this->insertBadge($member, 'streak', 1, revealed: true);
        $this->insertBadge($member, 'first_try', 1, revealed: true);
        foreach ([1, 2, 3, 4, 5] as $tier) {
            $this->insertBadge($member, 'puzzles_solved', $tier, revealed: true);
        }
        // Somebody else holds the Bronze streak and the Diamond too: first_try's Bronze is the rarer Bronze, and the
        // Diamond - common or not - still comes before every Bronze (Jan 2026-10-12: "Early Adopter, then Diamond")
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'streak', 1, revealed: true);
        foreach ([1, 2, 3, 4, 5] as $tier) {
            $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', $tier, revealed: true);
        }
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::MEMBER_PAGE);

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('.achievement-shelf > .achievement-cell a')->each(static fn ($a): string => (string) $a->attr('href'));
        self::assertStringStartsWith('/en/achievements/supporter/info', $links[0]);
        self::assertStringStartsWith('/en/achievements/puzzles_solved/info', $links[1]);
        self::assertStringStartsWith('/en/achievements/first_try/info', $links[2]);
        self::assertStringStartsWith('/en/achievements/streak/info', $links[3]);
        self::assertCount(17, $links);
    }

    public function testTheProfileShowcaseHasTheSameOrderEarlyAdopterThenTheHighestTier(): void
    {
        $browser = self::createClient();
        $member = PlayerFixture::PLAYER_WITH_STRIPE;
        $this->insertBadge($member, 'streak', 1, revealed: true);
        $this->insertBadge($member, 'supporter', null, revealed: true);
        foreach ([1, 2, 3, 4, 5] as $tier) {
            $this->insertBadge($member, 'puzzles_solved', $tier, revealed: true);
        }
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/player-profile/' . $member);

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('.achievements-showcase-item')->each(static fn ($a): string => (string) $a->attr('href'));
        self::assertStringStartsWith('/en/achievements/supporter/info', $links[0] ?? '');
        self::assertStringStartsWith('/en/achievements/puzzles_solved/info?tier=5', $links[1] ?? '');
        self::assertStringStartsWith('/en/achievements/streak/info?tier=1', $links[2] ?? '');
    }

    private function insertBadge(string $playerId, string $type, null|int $tier, bool $revealed): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :playerId, :type, NOW(), :tier, :revealedAt)',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'type' => $type, 'tier' => $tier, 'revealedAt' => $revealed ? '2026-10-01 10:00:00' : null],
        );
    }
}
