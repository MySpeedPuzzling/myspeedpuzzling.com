<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

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
        // A teammate's Overview (docs/features/navigation PROF-03): her pair with him among his latest results
        yield 'teammate overview' => ['/en/player-profile/' . PlayerFixture::PLAYER_REGULAR];
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
     * Her results page (navigation PROF-00, `player_results`) is about her alone: for anybody she did not allow it is
     * her profile, which explains the privacy (implementation plan decision 69); the friend gets the list.
     */
    public function testHerResultsPageOpensForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $url = '/en/player-results/' . self::OWNER;

        $browser->request('GET', $url);
        self::assertResponseRedirects('/en/player-profile/' . self::OWNER, 302);

        foreach (self::STRANGERS as $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $browser->request('GET', $url);
            self::assertResponseRedirects('/en/player-profile/' . self::OWNER, 302);
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(self::OWNER_NAME, $this->get($browser, $url));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * The results page of a public player narrowed to their pair with her (?team=&category=duo, as a team page links
     * it): everybody may open it, only the friend sees her name.
     */
    public function testResultsOfAPairWithHerNameHerForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $pairId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($pairId);
        $url = '/en/player-results/' . PlayerFixture::PLAYER_REGULAR . '?team=' . $pairId . '&category=duo';

        self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url), 'A guest is shown a private player.');
        self::assertStringContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'), 'Guest pages stay shared-cacheable.');

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url), "A signed-in stranger ({$who}) is shown a private player.");
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString(self::OWNER_NAME, $this->get($browser, $url), 'The allowed friend does not see the private player here - the page is no canary.');
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
        // The Community landing's previews (navigation COM-01): solo 500-piece leaders, most active puzzlers
        yield 'community landing' => ['/en/community'];
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

        // The follower's own favorites list - the Players page no longer lists favorites
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $content = $this->get($browser, '/en/player-favorites/' . PlayerFixture::PLAYER_WITH_STRIPE);

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

    /**
     * A round's published official results (docs/features/competitions-management/official-results.md) list her by the
     * name the organiser recorded ("Gina Quick") - the official record - but nothing of her profile: no name of hers, no
     * link, no avatar, except for the friend.
     */
    public function testPublishedOfficialResultsLinkHerProfileForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $url = '/en/events/results-cup/results/group-b';
        $profileLink = 'href="/en/player-profile/' . self::OWNER . '"';
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET results_published_at = NOW() WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_GROUP_B],
        );

        $content = $this->get($browser, $url);
        self::assertStringContainsString('Gina Quick', $content, 'No canary: the official results do not list her.');
        self::assertStringNotContainsString($profileLink, $content, 'A guest is shown a private player.');
        self::assertStringNotContainsString(self::OWNER_NAME, $content, 'A guest is shown a private player.');
        self::assertStringContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'), 'Guest pages stay shared-cacheable.');

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $content = $this->get($browser, $url);
            self::assertStringContainsString('Gina Quick', $content);
            self::assertStringNotContainsString($profileLink, $content, "A signed-in stranger ({$who}) is shown a private player.");
            self::assertStringNotContainsString(self::OWNER_NAME, $content, "A signed-in stranger ({$who}) is shown a private player.");
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString($profileLink, $this->get($browser, $url), 'The allowed friend does not see her profile here - the page is no canary.');
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * The Inbox tabs (docs/features/navigation INB-01) are the viewer's own, so every viewer gets the same rows about her.
     * For you: a favorite's time of hers (SubscribedPlayerAddedTime, from before INB-03) names her to nobody - the type
     * is not listed any more, so the friend, who is allowed and holds the same unread row, is not shown her there either
     * (her times are on Home › Favorites: PrivateProfileViewersControllerTest). Messages: a conversation names her to
     * whoever is in it - bilateral, by design (docs/features/private-profile-allow-list.md, "Left as is") - and the tab
     * lists the viewer's own conversations only: the friend talks with her, the strangers do not.
     * The For you row has no positive control since INB-03: no listed For you type masks a private player any more
     * (group edits name the editor on purpose, the other types are bilateral) - it only guards that the old rows stay
     * unlisted.
     */
    #[DataProvider('provideInboxTabs')]
    public function testOnlyTheFriendSeesHerOnTheirInboxTab(string $url, bool $friendSeesHer): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        foreach ([...array_values(self::STRANGERS), self::FRIEND] as $viewerId) {
            // TIME_02: her solo time
            $database->executeStatement(
                "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id) VALUES (:id, :player, 'SubscribedPlayerAddedTime', NOW(), :time)",
                ['id' => Uuid::uuid7()->toString(), 'player' => $viewerId, 'time' => PuzzleSolvingTimeFixture::TIME_02],
            );
        }

        $conversationId = Uuid::uuid7()->toString();
        $database->executeStatement(
            "INSERT INTO conversation (id, initiator_id, recipient_id, status, created_at, responded_at, last_message_at) VALUES (:id, :owner, :friend, 'accepted', NOW(), NOW(), NOW())",
            ['id' => $conversationId, 'owner' => self::OWNER, 'friend' => self::FRIEND],
        );
        $database->executeStatement(
            'INSERT INTO chat_message (id, conversation_id, sender_id, content, sent_at) VALUES (:id, :conversation, :owner, :content, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'conversation' => $conversationId, 'owner' => self::OWNER, 'content' => 'Hello'],
        );

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url), "A signed-in stranger ({$who}) is shown a private player.");
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        $content = $this->get($browser, $url);

        if ($friendSeesHer) {
            self::assertStringContainsString(self::OWNER_NAME, $content, 'The allowed friend does not see her here - the tab is no canary.');
        } else {
            self::assertStringNotContainsString(self::OWNER_NAME, $content, 'A favorite\'s time is listed again (INB-03).');
        }

        self::assertStringNotContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'), 'A page naming a private player must never be shared-cacheable.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideInboxTabs(): iterable
    {
        // [the tab, whether the friend is shown her there]
        yield 'inbox for you' => ['/en/notifications', false];
        yield 'inbox messages' => ['/en/messages', true];
    }

    /**
     * The Hub's Favorites feed renders with the page for a player who follows somebody, with "N new" on its tab
     * (docs/features/navigation INB-04b). Everybody here follows her: the strangers never get her name - her own times
     * are left out, a pair of hers tracked by a public player shows her as a Hidden Puzzler - and "N new" counts exactly
     * the rows they are shown; the friend gets her name.
     */
    public function testOnlyTheFriendSeesHerInTheirHubFavorites(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        foreach ([...array_values(self::STRANGERS), self::FRIEND] as $viewerId) {
            $database->executeStatement(
                "UPDATE player SET favorite_players = :favorites, favorites_seen_at = '2000-01-01 00:00:00' WHERE id = :id",
                ['favorites' => json_encode([self::OWNER]), 'id' => $viewerId],
            );
        }

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $content = $this->get($browser, '/en/hub');
            self::assertStringNotContainsString(self::OWNER_NAME, $content, "A signed-in stranger ({$who}) is shown a private player.");
            self::assertSame(self::shownNewRows($content), self::newCount($content), "A signed-in stranger ({$who}) gets rows counted that the feed does not show.");
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        $content = $this->get($browser, '/en/hub');
        self::assertStringContainsString(self::OWNER_NAME, (new Crawler($content))->filter('#recent-favorites-activity')->html(), 'The allowed friend does not see her here - the feed is no canary.');
        self::assertGreaterThan(0, self::newCount($content), 'The allowed friend does not get her times counted as new.');
        self::assertSame(self::shownNewRows($content), self::newCount($content));
        self::assertStringNotContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'), 'A page naming a private player must never be shared-cacheable.');
    }

    private static function newCount(string $content): int
    {
        // The number the pill shows on narrow phones ("3", "20+")
        $badge = (new Crawler($content))->filter('.hub-feed-new .hub-feed-new-short');

        return $badge->count() === 0 ? 0 : (int) $badge->text();
    }

    private static function shownNewRows(string $content): int
    {
        return (new Crawler($content))->filter('#recent-favorites-activity tr.ra-new')->count();
    }

    private function get(KernelBrowser $browser, string $url): string
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return (string) $browser->getResponse()->getContent();
    }

    /**
     * The Menu (docs/features/navigation MENU-01) and its lazy numbers (menu_counts) are the viewer's own: neither names
     * a private player - for a stranger or for the friend she allowed.
     */
    public function testTheMenuAndItsNumbersNameNobody(): void
    {
        $browser = self::createClient();

        foreach ([...array_values(self::STRANGERS), self::FRIEND] as $viewer) {
            TestingLogin::asPlayer($browser, $viewer);

            $browser->request('GET', '/en/menu-counts.json');
            self::assertResponseIsSuccessful();
            $content = (string) $browser->getResponse()->getContent();
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertSame(['organised', 'review'], array_keys($data));
            self::assertTrue($data['organised'] === null || is_int($data['organised']));
            self::assertIsInt($data['review']);
            self::assertStringNotContainsString(self::OWNER, $content, $viewer);
            self::assertStringNotContainsString(self::OWNER_NAME, $content, $viewer);

            $crawler = $browser->request('GET', '/en/menu');
            self::assertResponseIsSuccessful();
            $menu = $crawler->filter('main #site-menu')->html();
            self::assertStringNotContainsString(self::OWNER, $menu, $viewer);
            self::assertStringNotContainsString(self::OWNER_NAME, $menu, $viewer);
        }
    }
}
