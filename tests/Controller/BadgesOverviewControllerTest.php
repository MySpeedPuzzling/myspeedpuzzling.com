<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\AchievementCounts;
use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The achievements catalogue is public since the launch (no feature flag) - guests, free players and members alike.
 * Layout and orders: docs/features/xp-levels/ux-polish/design-spec.md §1.
 */
final class BadgesOverviewControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testAnonymousVisitorSeesTheCatalogue(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }

    public function testFreePlayerSeesTheCatalogue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }

    public function testMemberSeesTheCatalogue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
    }

    public function testAMembersProfileShowsTheirAchievementsShowcase(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, 'streak', " . TestClock::SQL_NOW . ", 1, " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.achievements-showcase .achievements-showcase-item'));
        self::assertCount(1, $crawler->filter('.achievements-showcase a.achievements-showcase-summary[href^="/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE . '?return="]'));
    }

    public function testAMembersShelfStartsWithTheRevealThenTheBestTiersThenTheProgress(): void
    {
        $browser = self::createClient();
        $member = PlayerFixture::PLAYER_WITH_STRIPE;
        $this->insertBadge($member, 'team_player', 1, revealed: true);
        $this->insertBadge($member, 'team_player', 2, revealed: false);
        $this->insertBadge($member, 'puzzles_solved', 1, revealed: true);
        $this->insertBadge($member, 'puzzles_solved', 2, revealed: true);
        $this->insertBadge($member, 'streak', 1, revealed: true);
        $this->insertBadge($member, 'first_try', 1, revealed: true);
        // Somebody else holds the Bronze streak too: first_try's Bronze is the rarer one
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'streak', 1, revealed: true);
        TestingLogin::asPlayer($browser, $member);

        // The shelf lives on the member's own achievements page (the catalogue is the public reference)
        $crawler = $browser->request('GET', '/en/player-achievements/' . $member);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievements-summary', '4 of ' . AchievementCounts::earnable() . ' achievements · 6 of ' . AchievementCounts::tiers() . ' tiers');

        $cells = $crawler->filter('.achievement-shelf > .achievement-cell');
        self::assertCount(AchievementCounts::earnable(), $cells);
        // 1. the unrevealed tier - an action; 2. earned, highest tier first, rarer first within a tier
        self::assertCount(1, $cells->eq(0)->filter('a[href="/en/my/achievement-reveals"]'));
        self::assertStringContainsString('Team Spirit', $cells->eq(0)->text());
        self::assertStringStartsWith('/en/achievements/puzzles_solved/info?tier=2&player=' . $member . '&from=shelf&return=', (string) $cells->eq(1)->filter('a')->attr('href'));
        self::assertStringStartsWith('/en/achievements/first_try/info?tier=1&player=' . $member, (string) $cells->eq(2)->filter('a')->attr('href'));
        self::assertStringStartsWith('/en/achievements/streak/info?tier=1&player=' . $member, (string) $cells->eq(3)->filter('a')->attr('href'));
        // 3. the rest by progress, highest first (cells without anything to measure last)
        $progress = [];
        for ($i = 4; $i < AchievementCounts::earnable(); $i++) {
            $bar = $cells->eq($i)->filter('[role="progressbar"]');
            $progress[] = $bar->count() > 0 ? (int) $bar->attr('aria-valuenow') : -1;
        }
        $sorted = $progress;
        rsort($sorted);
        self::assertSame($sorted, $progress);
        // Earned seals in colour with tier name + pips, unearned ones in the locked look
        self::assertSelectorTextContains('.achievement-shelf > .achievement-cell:nth-child(2) .achievement-cell-tier', 'Silver');
        self::assertCount(2, $cells->eq(1)->filter('.achievement-pips i.is-held'));
        self::assertCount(0, $cells->eq(1)->filter('img.badge-art-locked'));
        self::assertCount(1, $cells->eq(15)->filter('img.badge-art-locked'));
    }

    public function testAMembersClosestNextStepsAreTheirHighestProgresses(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievements-next-title', 'Closest next steps');
        $steps = $crawler->filter('.achievements-next-row');
        self::assertGreaterThanOrEqual(1, $steps->count());
        self::assertLessThanOrEqual(3, $steps->count());

        $percents = $steps->filter('[role="progressbar"]')->each(static fn ($bar): int => (int) $bar->attr('aria-valuenow'));
        $sorted = $percents;
        rsort($sorted);
        self::assertSame($sorted, $percents);
        // The highest step is the highest progress of the whole shelf
        $shelf = $crawler->filter('.achievement-shelf [role="progressbar"]')->each(static fn ($bar): int => (int) $bar->attr('aria-valuenow'));
        self::assertNotSame([], $shelf);
        self::assertSame(max($shelf), $percents[0]);
        // Requirement as text, the AP it brings, the sheet opens on the next tier - the viewer's own
        self::assertStringContainsString('AP', $steps->first()->text());
        self::assertStringContainsString('/info?tier=', (string) $steps->first()->attr('href'));
    }

    public function testTheAchievementsOfARecentDropAreTaggedNew(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();

        $crawler = $browser->request('GET', '/en/achievements');
        self::assertCount(0, $crawler->filter('.achievement-new-chip'), 'Before the drop of 29 October 2026');

        /** @var MockClock $clock the test environment's clock (config/services_test.php) */
        $clock = $browser->getContainer()->get('clock');
        $clock->modify('2026-11-01 10:00:00');
        $crawler = $browser->request('GET', '/en/achievements');

        self::assertCount(5, $crawler->filter('.achievement-new-chip'));
        self::assertCount(1, $crawler->filter('article#night_owl .achievement-new-chip'));
        self::assertCount(0, $crawler->filter('article#puzzles_solved .achievement-new-chip'), 'The launch came with no drop');

        // A member's shelf: the same five, on the cell's "New" pill
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertCount(5, $crawler->filter('.achievement-cell-new.is-drop'));
        self::assertStringContainsString('added recently', (string) $crawler->filter('.achievement-shelf a.achievement-cell-link[href^="/en/achievements/competitor/info"]')->attr('aria-label'));

        $clock->modify('2026-11-28 15:00:00');
        $crawler = $browser->request('GET', '/en/achievements');
        self::assertCount(0, $crawler->filter('.achievement-new-chip'), 'New for 30 days');
    }

    public function testTheCatalogueIsAReferenceThatReadsNothingPersonal(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', 1, revealed: true);
        // Rarity counts puzzlers with XP
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET xp_total = 100 WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        // The tease on top; no shelf, no personal parts
        self::assertSelectorExists('.achievements-tease a.btn[href="/en/membership"]');
        self::assertSelectorNotExists('.achievements-summary');
        self::assertSelectorNotExists('.achievement-shelf');
        self::assertSelectorNotExists('.achievements-next');
        // Every achievement with all its tiers - what each takes and how many hold it - plus Early Adopter
        self::assertCount(AchievementCounts::all(), $crawler->filter('article.achievement-ref'));
        self::assertCount(AchievementCounts::tiers(), $crawler->filter('.achievement-ref-tier'));
        $explorer = $crawler->filter('article#puzzles_solved');
        self::assertStringContainsString('Solve 10 distinct puzzles', $explorer->filter('.achievement-ref-tier')->first()->text());
        self::assertStringContainsString('1 puzzler', $explorer->filter('.achievement-ref-tier')->first()->text());
        self::assertSame('/en/achievements/puzzles_solved', $explorer->filter('.achievement-ref-title a')->attr('href'));
        self::assertStringContainsString('Nobody yet', $crawler->filter('article#marathoner .achievement-ref-tier')->first()->text());

        // Nothing personal is read: neither the badge rows nor the stats behind the progress
        $personal = array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => (str_contains($sql, 'FROM badge') && str_contains($sql, 'player_id = '))
                || str_contains($sql, 'first_try_solves'),
        );
        self::assertSame([], array_values($personal));
    }

    public function testAMemberGetsTheSameReferenceAndOneLineToTheirOwnAchievements(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.achievement-shelf');
        self::assertSelectorNotExists('.achievements-tease');
        self::assertCount(AchievementCounts::all(), $crawler->filter('article.achievement-ref'));
        self::assertSelectorExists('.achievements-own-line a[href="/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE . '"]');
    }

    public function testTheCatalogueSitsUnderHowProgressWorks(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.page-crumbs-trail a[href="/en/how-progress-works"]'));
        $jsonLd = $crawler->filter('script[type="application/ld+json"]')->each(static fn ($node): string => $node->text());
        self::assertNotEmpty(array_filter($jsonLd, static fn (string $json): bool => str_contains($json, 'BreadcrumbList')));
        // "How achievements work" on top says what the numbers mean
        self::assertSelectorTextContains('.achievements-explainer', 'counts everybody who has logged at least one puzzle');
        self::assertSelectorTextContains('.achievements-explainer', 'can step back');
    }

    public function testAHeldEarlyAdopterCountsAsAnAchievement(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'supporter', null, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        // Early Adopter can no longer be earned: one more achievement for its holders, in the earned count and the total
        self::assertSelectorTextContains('.achievements-own-line', '2 of ' . AchievementCounts::all() . ' achievements');
        self::assertSelectorTextContains('.achievements-own-line', '1 of ' . AchievementCounts::tiers() . ' tiers');
        // Its card says what it means and that it can no longer be earned, with how many hold it
        $card = $crawler->filter('article#supporter');
        self::assertStringContainsString('believed in MySpeedPuzzling early', $card->text());
        self::assertStringContainsString('can no longer be earned', $card->text());
        self::assertStringContainsString('1 puzzler', $card->text());
    }

    public function testWithoutEarlyAdopterTheTotalIsTheAchievementsThatCanBeEarned(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_WITH_STRIPE, 'puzzles_solved', 1, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievements-own-line', '1 of ' . AchievementCounts::earnable() . ' achievements');
    }

    public function testHowAchievementsWorkIsOnTopUntilHiddenAndCanBeOpenedAgain(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/achievements');
        $explainer = $crawler->filter('details#how-achievements-work');
        self::assertCount(1, $explainer);
        self::assertNotNull($explainer->attr('open'), 'open until the player hides it');
        self::assertSame('collapsible-hint', $explainer->attr('data-controller'));
        self::assertSame('How achievements work', trim($explainer->filter('summary')->text()));

        // "Got it, hide" stores the dismissal: from then on it is one line - the summary - that opens it again
        $browser->request('POST', '/en/dismiss-hint', ['type' => 'achievements_explainer']);
        self::assertResponseIsSuccessful();
        $crawler = $browser->request('GET', '/en/achievements');
        $explainer = $crawler->filter('details#how-achievements-work');
        self::assertCount(1, $explainer);
        self::assertNull($explainer->attr('open'));
    }

    private function insertBadge(string $playerId, string $type, null|int $tier, bool $revealed): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :playerId, :type, ' . TestClock::SQL_NOW . ', :tier, :revealedAt)',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'type' => $type, 'tier' => $tier, 'revealedAt' => $revealed ? '2026-10-01 10:00:00' : null],
        );
    }
}
