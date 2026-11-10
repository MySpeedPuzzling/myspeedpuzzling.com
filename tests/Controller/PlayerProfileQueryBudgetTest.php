<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The player profile costs a fixed number of statements per viewer, measured on main before the profile's performance
 * work and pinned exactly - a higher number is a bug to explain, not a new number. The second request is counted (the
 * first warms the caches). The ratings world of PlayerProfileRenderCharacterizationTest is seeded, so the rank
 * fragments of the header chip and the rating card run.
 */
final class PlayerProfileQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, null|string, int}>
     */
    public static function providePages(): iterable
    {
        // Measured on main 791fb38f7 (2026-11-10); - 1 since a player's pairs and teams are one statement (a player with both: - 2), - 1 since the chart's two filters are one statement
        // PLAYER_REGULAR is in the referral program (AffiliateFixture): its three supporter statements are in every case
        yield 'regular, guest' => [PlayerFixture::PLAYER_REGULAR, null, 12];
        yield 'regular, signed-in other' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES, 17];
        yield 'regular, owner' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_REGULAR, 18];
        yield 'regular, member' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, 22];
        yield 'with favorites, guest' => [PlayerFixture::PLAYER_WITH_FAVORITES, null, 8];
        yield 'with favorites, member' => [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE, 18];
        // Masked: the profile row only - no chart, no rating card, no results
        yield 'private, guest' => [PlayerFixture::PLAYER_PRIVATE, null, 1];
        yield 'private, owner' => [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_PRIVATE, 15];
        yield 'private, allow-listed viewer' => [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES, 14];
    }

    #[DataProvider('providePages')]
    public function testThePageCost(string $profileId, null|string $viewerId, int $statements): void
    {
        $browser = self::createClient();
        PlayerProfileRenderCharacterizationTest::ratingsWorld();

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        self::assertSame($statements, $this->measure($browser, '/en/player-profile/' . $profileId), ($viewerId ?? 'guest') . ' on ' . $profileId);
    }

    private function measure(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
