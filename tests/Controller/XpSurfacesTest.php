<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Since the launch (no feature flag) the XP surfaces are there for everybody - a free player's pages render with
 * them, members get the achievement reveal.
 */
final class XpSurfacesTest extends WebTestCase
{
    public function testThePagesWithXpSurfacesRenderForAFreePlayer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (
            [
            '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE,
            '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_06,
            '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01,
            '/en/faq',
            ] as $url
        ) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testAnUnknownBadgeCannotBeRevealed(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/badges/018d0000-0000-0000-0000-00000000dead/reveal');

        self::assertResponseStatusCodeSame(404);
    }

    public function testBadgeRevealsPageRendersForAMember(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/my/achievement-reveals');

        self::assertResponseIsSuccessful();
    }

    public function testAFreePlayerIsSentToMembershipFromTheBadgeReveals(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/my/achievement-reveals');

        self::assertResponseRedirects('/en/membership');
    }

    public function testMembershipPageInvitesAMemberToTheirUnrevealedAchievements(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier) VALUES (:id, :player, 'puzzles_solved', NOW(), 1)",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $browser->request('GET', '/en/membership');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#xp-reveal-invite a[href="/en/my/achievement-reveals"]');
    }
}
