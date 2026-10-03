<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A private player is shown to the players on their allow list and to NOBODY else
 * (docs/features/private-profile-allow-list.md). The read side has no chokepoint - every query is
 * hand-written SQL - so nothing but a test can notice a page that gives a private player away.
 *
 * PrivateProfileViewerFixture: PLAYER_PRIVATE ("Jane Smith") allows PLAYER_WITH_FAVORITES only.
 * Every page is fetched as a guest, as signed-in strangers (a member, an admin outside /admin) and
 * as the friend. **Add every new page that lists players here.**
 */
final class PrivateProfileCanaryTest extends WebTestCase
{
    private const string OWNER = PlayerFixture::PLAYER_PRIVATE;
    private const string OWNER_NAME = 'Jane Smith';
    private const string FRIEND = PlayerFixture::PLAYER_WITH_FAVORITES;

    /** Signed-in viewers the owner never allowed: a paying member and an admin */
    private const array STRANGERS = [
        'member' => PlayerFixture::PLAYER_WITH_STRIPE,
        'admin' => PlayerFixture::PLAYER_ADMIN,
    ];

    #[DataProvider('providePagesWhereFriendsSeeThePrivatePlayer')]
    public function testOnlyTheAllowedFriendSeesThePrivatePlayer(string $url, string $needle = self::OWNER_NAME): void
    {
        $browser = self::createClient();

        self::assertStringNotContainsString($needle, $this->get($browser, $url), 'A guest is shown a private player.');
        self::assertStringContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'), 'Guest pages stay shared-cacheable.');

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            self::assertStringNotContainsString($needle, $this->get($browser, $url), "A signed-in stranger ({$who}) is shown a private player.");
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(
            $needle,
            $this->get($browser, $url),
            'The allowed friend does not see the private player here - the page is no canary (or the allow list is broken).',
        );

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringNotContainsString('public', $cacheControl, 'A page naming a private player must never be shared-cacheable.');
        self::assertStringContainsString('no-store', $cacheControl);
    }

    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function providePagesWhereFriendsSeeThePrivatePlayer(): iterable
    {
        yield 'profile' => ['/en/player-profile/' . self::OWNER];
        yield 'puzzle detail, solo time' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01];
        yield 'puzzle detail, pair time' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_1000_01];
        yield 'recent activity' => ['/en/recent-activity'];
        // The search highlights the match inside the name, so the link to her profile is the tell
        yield 'puzzlers search by name' => ['/en/puzzlers?search=Jane', '/en/player-profile/' . self::OWNER];
        // A board everybody sees her row on, as a Hidden Puzzler - same position for every viewer
        yield 'ladder pairs 1000' => ['/en/ladder/pairs/1000-pieces'];
        yield 'puzzle library' => ['/en/puzzle-library/' . self::OWNER];
    }

    /**
     * Result detail (docs/features/puzzle-result-detail.md) of her pair with a public player: everybody may open
     * it, only the friend sees her name.
     */
    public function testResultDetailOfHerPairNamesHerForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $url = '/en/result/' . PuzzleSolvingTimeFixture::TIME_12;

        self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url), 'A guest is shown a private player.');

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url), "A signed-in stranger ({$who}) is shown a private player.");
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(self::OWNER_NAME, $this->get($browser, $url));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * The compare page (docs/features/player-comparison.md): a shared comparison of her - solo, or her pair with a public
     * player - names her for the friend only. Strangers get the pair with her masked, and her alone not at all.
     */
    public function testComparePageNamesHerForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $pairId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($pairId);

        $urls = [
            'solo' => '/en/compare?kind=solo&with=p-' . self::OWNER,
            'pair' => '/en/compare?kind=pairs&with=t-' . $pairId,
        ];

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);

            foreach ($urls as $what => $url) {
                self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url), "A signed-in stranger ({$who}) is shown a private player ({$what}).");
            }
        }

        TestingLogin::asPlayer($browser, self::FRIEND);

        foreach ($urls as $what => $url) {
            self::assertStringContainsString(self::OWNER_NAME, $this->get($browser, $url), "The allowed friend does not see the private player ({$what}).");
            self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    /**
     * Her own results do not exist for anybody she did not allow - a page about one player cannot be masked.
     */
    public function testResultDetailOfHerSoloTimeExistsForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $url = '/en/result/' . PuzzleSolvingTimeFixture::TIME_02;

        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        foreach (self::STRANGERS as $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(self::OWNER_NAME, $this->get($browser, $url));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * Rankings that leave private players OUT leave them out for everybody, the friend included -
     * nobody is ranked differently for different viewers. (Boards that list them as a Hidden Puzzler
     * - pairs, groups, most active - keep every row and position and only name her for the friend.)
     */
    #[DataProvider('provideGlobalRankings')]
    public function testGlobalRankingsShowThePrivatePlayerToNobody(string $url): void
    {
        $browser = self::createClient();

        foreach ([null, ...array_values(self::STRANGERS), self::FRIEND] as $viewerId) {
            if ($viewerId !== null) {
                TestingLogin::asPlayer($browser, $viewerId);
            }

            self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideGlobalRankings(): iterable
    {
        yield 'ladder' => ['/en/ladder'];
        yield 'ladder solo 500' => ['/en/ladder/solo/500-pieces'];
        yield 'MSP rating' => ['/en/msp-rating'];
        yield 'players of a country' => ['/en/players-from-country/us'];
        yield 'puzzlers (most favourited)' => ['/en/puzzlers'];
    }

    public function testPlayerPickerFindsThePrivatePlayerByNameForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $url = '/en/player-search-autocomplete/?query=Jane';

        foreach (self::STRANGERS as $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $content = $this->get($browser, $url);
            self::assertStringNotContainsString(self::OWNER_NAME, $content);
            self::assertStringNotContainsString(self::OWNER, $content);
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(self::OWNER, $this->get($browser, $url));
    }

    public function testStrangersFindThePrivatePlayerByExactCodeOnlyAndWithoutAName(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Part of the code is not enough
        self::assertStringNotContainsString(self::OWNER, $this->get($browser, '/en/player-search-autocomplete/?query=player'));

        // The whole code is - that is how a private player is added to a pair/team time
        $content = $this->get($browser, '/en/player-search-autocomplete/?query=player2');
        self::assertStringContainsString(self::OWNER, $content);
        self::assertStringNotContainsString(self::OWNER_NAME, $content);
        self::assertStringNotContainsString('Jane', $content);
    }

    public function testAFollowedPrivatePlayerIsListedByCodeNotAsABlankRow(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET favorite_players = :favorites WHERE id = :id',
            ['favorites' => json_encode([self::OWNER]), 'id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $content = $this->get($browser, '/en/puzzlers');

        self::assertStringNotContainsString(self::OWNER_NAME, $content);
        self::assertStringContainsString('Hidden Puzzler', $content);
        self::assertStringContainsString('#PLAYER2', $content);
    }

    /**
     * "Who has you in favorites" on the favorite puzzlers page (the owner's alone): the friend sees her name, anybody
     * else only a count - not even her code.
     */
    public function testFavoritesPageNamesAPrivateFollowerToTheFriendOnly(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET favorite_players = :favorites WHERE id = :id',
            ['favorites' => json_encode([self::FRIEND, ...array_values(self::STRANGERS)]), 'id' => self::OWNER],
        );

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $content = $this->get($browser, '/en/player-favorites/' . $strangerId);
            self::assertStringNotContainsString(self::OWNER_NAME, $content, "A signed-in stranger ({$who}) is shown a private follower.");
            self::assertStringNotContainsString(self::OWNER, $content, "A signed-in stranger ({$who}) is shown a private follower.");
            self::assertStringNotContainsString('PLAYER2', $content, "A signed-in stranger ({$who}) is shown a private follower's code.");
            self::assertStringContainsString('1 private puzzler', $content, 'No canary: the private follower is not counted.');
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(self::OWNER_NAME, $this->get($browser, '/en/player-favorites/' . self::FRIEND));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * The friend reads the name on the page; the head of that page follows the player's own
     * setting, so nothing the friend's browser shares, unfurls or "reads later" carries an identity.
     */
    public function testFriendsProfilePageHeadStaysAnonymous(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::FRIEND);

        $crawler = $browser->request('GET', '/en/player-profile/' . self::OWNER);
        self::assertResponseIsSuccessful();

        $head = $crawler->filter('head')->html();
        self::assertStringNotContainsString(self::OWNER_NAME, $head);
        self::assertStringNotContainsString('Jane', $head);
        self::assertStringContainsString('noindex', $head);
        self::assertCount(0, $crawler->filter('script[type="application/ld+json"]')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'ProfilePage'),
        ));

        self::assertStringContainsString(self::OWNER_NAME, $crawler->filter('body')->html());
    }

    public function testRemovingTheFriendHidesThePlayerAgain(): void
    {
        $browser = self::createClient();
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(self::OWNER_NAME, $this->get($browser, $url));

        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM private_profile_viewer');

        self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url));
        self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, '/en/player-profile/' . self::OWNER));
    }

    public function testTheListIsOneDirectional(): void
    {
        $browser = self::createClient();

        // The friend goes private too, without allowing anybody: the owner who allowed them gains nothing
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET is_private = true WHERE id = :id',
            ['id' => self::FRIEND],
        );

        TestingLogin::asPlayer($browser, self::OWNER);

        self::assertStringNotContainsString('Michael Johnson', $this->get($browser, '/en/player-profile/' . self::FRIEND));
    }

    public function testABlockOutranksTheAllowList(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

        // The owner blocks the friend (as an admin would impose it by SQL - no handler clean-up)
        $database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (gen_random_uuid(), :owner, :friend, NOW(), 'admin')",
            ['owner' => self::OWNER, 'friend' => self::FRIEND],
        );

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url));
    }

    private function get(KernelBrowser $browser, string $url): string
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return (string) $browser->getResponse()->getContent();
    }
}
