<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
            "INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, 'streak', NOW(), 1, NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.achievements-showcase .achievements-showcase-item'));
        self::assertCount(1, $crawler->filter('.achievements-showcase a.achievements-showcase-summary[href="/en/player-achievements/' . PlayerFixture::PLAYER_WITH_STRIPE . '"]'));
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

        $crawler = $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.achievements-filter a.active', 'All · 16');
        self::assertSelectorTextContains('.achievements-summary', '4 of 16 achievements · 6 of 80 tiers');

        $cells = $crawler->filter('.achievement-shelf > .achievement-cell');
        self::assertCount(16, $cells);
        // 1. the unrevealed tier - an action; 2. earned, highest tier first, rarer first within a tier
        self::assertCount(1, $cells->eq(0)->filter('a[href="/en/my/achievement-reveals"]'));
        self::assertStringContainsString('Team Spirit', $cells->eq(0)->text());
        self::assertSame('/en/achievements/puzzles_solved/info?tier=2', $cells->eq(1)->filter('a')->attr('href'));
        self::assertStringStartsWith('/en/achievements/first_try/info?tier=1', (string) $cells->eq(2)->filter('a')->attr('href'));
        self::assertStringStartsWith('/en/achievements/streak/info?tier=1', (string) $cells->eq(3)->filter('a')->attr('href'));
        // 3. the rest by progress, highest first (cells without anything to measure last)
        $progress = [];
        for ($i = 4; $i < 16; $i++) {
            $bar = $cells->eq($i)->filter('[role="progressbar"]');
            $progress[] = $bar->count() > 0 ? (int) $bar->attr('aria-valuenow') : -1;
        }
        $sorted = $progress;
        rsort($sorted);
        self::assertSame($sorted, $progress);
        // The catalogue is the viewer's own: no "player" in the sheet links
        self::assertCount(0, $crawler->filter('.achievement-shelf a[href*="player="]'));
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

        $crawler = $browser->request('GET', '/en/achievements');

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
        self::assertStringNotContainsString('player=', (string) $steps->first()->attr('href'));
        self::assertStringContainsString('/info?tier=', (string) $steps->first()->attr('href'));
    }

    public function testTheNonMemberCatalogueIsAShopWindowAndReadsNothingPersonal(): void
    {
        $browser = self::createClient();
        $this->insertBadge(PlayerFixture::PLAYER_REGULAR, 'puzzles_solved', 1, revealed: true);
        // Rarity counts puzzlers with XP
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET xp_total = 100 WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/achievements?show=next');

        self::assertResponseIsSuccessful();
        // All · Rarest only, "next" falls back to All; the tease on top; no summary, no steps, no progress
        $filters = $crawler->filter('.achievements-filter a')->each(static fn ($node): string => trim($node->text()));
        self::assertSame(['All · 16', 'Rarest first · 16'], $filters);
        self::assertSelectorExists('.achievements-tease a.btn[href="/en/membership"]');
        self::assertSelectorNotExists('.achievements-summary');
        self::assertSelectorNotExists('.achievements-next');
        self::assertSelectorNotExists('.achievement-progress');
        // Every seal at Bronze, in colour, with how many puzzlers hold it
        $cells = $crawler->filter('.achievement-cell');
        self::assertCount(16, $cells);
        self::assertCount(16, $crawler->filter('.achievement-cell img.badge-art[src*="-t1.webp"]'));
        self::assertSelectorNotExists('.badge-art-locked');
        self::assertStringContainsString('5 tiers · Bronze held by', $crawler->filter('.achievement-cell a[href^="/en/achievements/puzzles_solved/info"]')->text());
        self::assertStringContainsString('5 tiers · nobody holds Bronze yet', $crawler->filter('.achievement-cell a[href^="/en/achievements/marathoner/info"]')->text());

        // Nothing personal is read: neither the badge rows nor the stats behind the progress
        $personal = array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => (str_contains($sql, 'FROM badge') && str_contains($sql, 'player_id = '))
                || str_contains($sql, 'first_try_solves'),
        );
        self::assertSame([], array_values($personal));
    }

    public function testTheCatalogueSitsUnderHowProgressWorks(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/achievements');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.page-crumbs-trail a[href="/en/how-progress-works"]'));
        $jsonLd = $crawler->filter('script[type="application/ld+json"]')->each(static fn ($node): string => $node->text());
        self::assertNotEmpty(array_filter($jsonLd, static fn (string $json): bool => str_contains($json, 'BreadcrumbList')));
        // The footnote says what the numbers mean
        self::assertSelectorTextContains('.achievements-footnote', 'counts everybody with XP');
        self::assertSelectorTextContains('.achievements-footnote', 'can step back');
    }

    private function insertBadge(string $playerId, string $type, int $tier, bool $revealed): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :playerId, :type, NOW(), :tier, :revealedAt)',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'type' => $type, 'tier' => $tier, 'revealedAt' => $revealed ? '2026-10-01 10:00:00' : null],
        );
    }
}
