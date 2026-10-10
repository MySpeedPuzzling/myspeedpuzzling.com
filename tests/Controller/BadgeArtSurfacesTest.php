<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The badge pictures on the profile strip and in the explainer modal (badge_art(), public/img/badges/). Admin viewer
 * while the xp-system flag is active - the admin fixture is also a member.
 */
final class BadgeArtSurfacesTest extends WebTestCase
{
    public function testTheProfileStripShowsEachBadgeAndHidesAnUnrevealedOneBehindTheFlip(): void
    {
        $browser = self::createClient();
        $this->earn('streak', 3, revealed: true);
        $this->earn('speed_500_pieces', 1, revealed: false);
        $this->earn('supporter', null, revealed: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);

        self::assertResponseIsSuccessful();
        $strip = $crawler->filter('.xp-achievements-strip');

        // A badge opens the explainer with itself as the big picture
        $streak = $strip->filter('a[href="/en/achievements/streak/info?tier=3"] img.badge-art');
        self::assertCount(1, $streak);
        self::assertStringStartsWith('/img/badges/webp/144/streak-t3.webp?v=', (string) $streak->attr('src'));
        self::assertSame('72', $streak->attr('width'));

        self::assertCount(1, $strip->filter('img.badge-art[src^="/img/badges/webp/144/supporter-single.webp"]'));

        // The owner's unrevealed badge: a question mark until the first click turns it over
        self::assertCount(1, $strip->filter('.badge-flip .badge-flip-back.badge-art-mystery'));
        self::assertCount(1, $strip->filter('.badge-flip .badge-flip-front img.badge-art[src*="speed_500_pieces-t1.webp"]'));
    }

    public function testAFreeOwnerSeesTheirBestBadgesLocked(): void
    {
        $browser = self::createClient();
        $this->earn('team_player', 1, revealed: false);
        $this->earn('puzzles_solved', 5, revealed: false);
        $this->earn('streak', 2, revealed: false);
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM membership WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);

        self::assertResponseIsSuccessful();
        $locked = $crawler->filter('.xp-locked-strip img.badge-art.badge-art-locked');
        self::assertCount(3, $locked);
        self::assertStringContainsString('puzzles_solved-t5', (string) $locked->first()->attr('src'));
        self::assertStringContainsString('team_player-t1', (string) $locked->last()->attr('src'));
    }

    public function testTheExplainerShowsTheClickedBadgeBigElseTheFirstTierLocked(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/achievements/streak/info?tier=4', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        $hero = $crawler->filter('img.badge-art-hero');
        self::assertStringContainsString('/streak-t4.webp?v=', (string) $hero->attr('src'));
        self::assertStringNotContainsString('badge-art-locked', (string) $hero->attr('class'));
        // The tiers are rows of text (tier · requirement · rarity), not five more pictures
        self::assertCount(5, $crawler->filter('li.achievement-tier-row'));

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
