<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The badge pictures in the profile's achievements showcase and in the explainer modal (badge_art(),
 * public/img/badges/). The admin fixture is also a member.
 */
final class BadgeArtSurfacesTest extends WebTestCase
{
    public function testTheProfileShowcaseShowsEachBadgeBestFirstAndAnUnrevealedOneAsAQuestionMark(): void
    {
        $browser = self::createClient();
        $this->earn('streak', 3, revealed: true);
        $this->earn('speed_500_pieces', 1, revealed: false);
        $this->earn('supporter', null, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);

        self::assertResponseIsSuccessful();
        $showcase = $crawler->filter('.achievements-showcase');
        $items = $showcase->filter('.achievements-showcase-item');
        self::assertCount(3, $items);
        // Two tiered achievements + the held Early Adopter, which counts for its holders (in the total too)
        self::assertStringContainsString('3 of 17', $showcase->text());

        // Highest tier first: Gold streak, then the owner's unrevealed Bronze, then the single-tier one
        $streak = $items->eq(0)->filter('img.badge-art');
        // The sheet shows this player's medal (?player=), not the viewer's
        self::assertSame('/en/achievements/streak/info?tier=3&player=' . PlayerFixture::PLAYER_ADMIN, $items->eq(0)->attr('href'));
        self::assertStringStartsWith('/img/badges/webp/144/streak-t3.webp?v=', (string) $streak->attr('src'));
        self::assertSame('56', $streak->attr('width'));

        // The owner's unrevealed badge: a question mark that leads to the reveal page, its picture is not in the page
        self::assertSame('/en/my/achievement-reveals', $items->eq(1)->attr('href'));
        self::assertCount(1, $items->eq(1)->filter('.badge-art-mystery'));
        self::assertCount(0, $showcase->filter('img[src*="speed_500_pieces"]'));

        self::assertCount(1, $items->eq(2)->filter('img.badge-art[src^="/img/badges/webp/144/supporter-single.webp"]'));
    }

    public function testAFreeOwnerGetsOneQuietLineInsteadOfTheirBadges(): void
    {
        $browser = self::createClient();
        $this->earn('team_player', 1, revealed: false);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE player SET achievement_points = 5 WHERE id = :player', ['player' => PlayerFixture::PLAYER_ADMIN]);
        $connection->executeStatement('DELETE FROM membership WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_ADMIN]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.achievements-showcase'));
        self::assertCount(0, $crawler->filter('main img.badge-art, .container img.badge-art'));
        $teaser = $crawler->filter('.achievements-teaser');
        self::assertCount(1, $teaser);
        self::assertStringContainsString('You have earned achievements.', $teaser->text());
        self::assertCount(1, $teaser->filter('a[href="/en/membership"]'));
        self::assertCount(0, $teaser->filter('.ci-locked'));
    }

    public function testTheExplainerShowsTheClickedBadgeBigElseTheFirstTierLocked(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/achievements/streak/info?tier=4', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        $hero = $crawler->filter('img.badge-art-hero');
        self::assertStringContainsString('/streak-t4.webp?v=', (string) $hero->attr('src'));
        // The viewer holds no streak tier: the tapped tier is shown, greyed (design-spec §2, tier list 2A)
        self::assertStringContainsString('badge-art-locked', (string) $hero->attr('class'));
        // One row per tier, the requirement as text
        self::assertCount(5, $crawler->filter('li.medal-tier'));
        self::assertCount(5, $crawler->filter('.medal-tier-requirement'));

        // Opened without a badge by a viewer who has none of it yet
        $crawler = $browser->request('GET', '/en/achievements/streak/info?tier=junk', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        $hero = $crawler->filter('img.badge-art-hero');
        self::assertStringContainsString('streak-t1.webp', (string) $hero->attr('src'));
        self::assertStringContainsString('badge-art-locked', (string) $hero->attr('class'));
    }

    /**
     * The tier and every one below it, as the evaluator gap-fills them.
     */
    private function earn(string $type, null|int $tier, bool $revealed): void
    {
        $database = self::getContainer()->get(Connection::class);

        foreach ($tier === null ? [null] : range(1, $tier) as $earnedTier) {
            $database->executeStatement(
                'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, :type, NOW(), :tier, :revealed)',
                [
                    'id' => Uuid::uuid7()->toString(),
                    'player' => PlayerFixture::PLAYER_ADMIN,
                    'type' => $type,
                    'tier' => $earnedTier,
                    'revealed' => $revealed ? date('Y-m-d H:i:s') : null,
                ],
            );
        }
    }
}
