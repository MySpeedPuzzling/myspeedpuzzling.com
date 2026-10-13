<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The profile's results list narrowed to a pair/team or a tab (`?team=<id>&category=duo|group`, what team pages linked)
 * is the Results page now (docs/features/navigation PROF-05e): such a link answers 302 to Results with the same filter
 * - before anything is read, so it costs no statement of its own; Results answers an unknown, hidden or private player
 * itself. A profile without those parameters is the profile.
 */
final class PlayerProfileOldResultsLinksTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    private const string PAIR = '018d0000-0000-7000-8000-00000000beef';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideOldLinks(): iterable
    {
        // [the profile's query, the Results query]
        yield 'a pair (what team pages linked)' => ['?team=' . self::PAIR . '&category=duo', '?category=duo&team=' . self::PAIR];
        yield 'a team' => ['?team=' . self::PAIR . '&category=group', '?category=group&team=' . self::PAIR];
        yield 'a pair/team without a tab = the Pairs tab (as the profile read it)' => ['?team=' . self::PAIR, '?category=duo&team=' . self::PAIR];
        yield 'a pair/team never on the Solo tab' => ['?team=' . self::PAIR . '&category=solo', '?category=duo&team=' . self::PAIR];
        yield 'a tab alone' => ['?category=group', '?category=group'];
        yield 'a nonsense tab' => ['?category=trio', ''];
        yield 'a nonsense pair/team' => ['?team=%27%20OR%201=1&category=duo', '?category=duo'];
        yield 'not even a string' => ['?team[]=x', ''];
        yield 'other parameters stay behind' => ['?team=' . self::PAIR . '&category=duo&sort=fastest', '?category=duo&team=' . self::PAIR];
    }

    #[DataProvider('provideOldLinks')]
    public function testAnOldLinkGoesToResultsWithTheSameFilter(string $query, string $resultsQuery): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-profile/' . self::PLAYER . $query);

        self::assertResponseRedirects('/en/player-results/' . self::PLAYER . $resultsQuery, 302);
    }

    public function testEveryLocale(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/de/spieler-profil/' . self::PLAYER . '?team=' . self::PAIR . '&category=duo');
        self::assertResponseRedirects('/de/spieler-ergebnisse/' . self::PLAYER . '?category=duo&team=' . self::PAIR, 302);

        $browser->request('GET', '/profil-hrace/' . self::PLAYER . '?category=group');
        self::assertResponseRedirects('/vysledky-hrace/' . self::PLAYER . '?category=group', 302);
    }

    public function testTheRedirectReadsNothing(): void
    {
        $browser = self::createClient();
        $url = '/en/player-profile/' . self::PLAYER . '?team=' . self::PAIR . '&category=duo';

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);

        self::assertResponseRedirects();
        self::assertSame(0, $this->queryCount($browser), "A guest's redirect runs no statement:\n" . implode("\n", $this->executedSql($browser)));
    }

    public function testResultsAnswerForThePlayer(): void
    {
        $browser = self::createClient();

        // Unknown player: the redirect, then Results' 404
        $browser->request('GET', '/en/player-profile/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b?category=duo');
        self::assertResponseRedirects();
        $browser->followRedirect();
        self::assertResponseStatusCodeSame(404);

        // A private profile hidden from the viewer: Results sends back to the profile - without the old parameters
        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE . '?category=duo');
        $browser->followRedirect();
        self::assertResponseRedirects('/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE, 302);
    }

    public function testTheProfileItselfIsNoRedirect(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach (['', '?return=%2Fen%2Fhub', '?sort=fastest'] as $query) {
            $browser->request('GET', '/en/player-profile/' . self::PLAYER . $query);
            self::assertResponseIsSuccessful($query);
        }
    }
}
