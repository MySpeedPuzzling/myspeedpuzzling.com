<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Players pages read precomputed tables, one statement per section (docs/features/players-page/README.md,
 * "Performance budgets"). A guest's page is the whole cost; a signed-in page adds what every signed-in request loads
 * (profile, unread counts) plus Suggested for you.
 */
final class PlayersPageQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    #[DataProvider('provideGuestBudgets')]
    public function testGuestPageCost(string $url, int $budget): void
    {
        $browser = self::createClient();
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, $budget, $url);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideGuestBudgets(): iterable
    {
        // countries (scope switch + tiles + Cup share it), spotlight numbers, upcoming events, spotlight people, this week
        yield 'players page, world' => ['/en/puzzlers', 5];
        yield 'players page, a country' => ['/en/puzzlers?scope=cz', 5];
        // the cards, the countries of the "Where" select
        yield 'directory' => ['/en/puzzlers/all', 2];
        // public players exist (robots), spotlight (3), the cards
        yield 'country page' => ['/en/players-from-country/cz', 5];
        // the profile, the card's numbers
        yield 'player card' => ['/en/puzzler-card/' . PlayerFixture::PLAYER_ADMIN, 2];
    }

    public function testSignedInPageCost(): void
    {
        $browser = self::createClient();
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzlers');
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzlers');

        self::assertResponseIsSuccessful();
        // the guest's 5 + suggestions + what every signed-in request loads
        $this->assertQueryCountAtMost($browser, 10, 'players page, signed in');
    }
}
