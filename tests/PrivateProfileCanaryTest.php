<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\DeclaredAdvancementStep;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\PublishedStanding;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
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
    use AnalyticsStudioTesting;
    use UnfinishedResultSeeding;
    use TrackerlessTimeSeeding;

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
        $this->assertOnlyTheFriendSees(self::createClient(), $url, $needle);
    }

    /**
     * An unfinished result (docs/features/competitions-management/unfinished-results-plan.md) is a bottom row of the
     * puzzle page - on a puzzle she has no time of, so the bottom row is the only place that could name her
     */
    /**
     * A relay team she claimed (docs/features/event-results-import/solo-and-relays.md §2) - its row on the round page is
     * read by the round's own statement: her name for the friend only
     */
    public function testHerRelayTeamOnTheRoundPageNamesHerForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $round = $this->seedTrackerlessEvent(PuzzleFixture::PUZZLE_1500_01, secondPuzzleId: PuzzleFixture::PUZZLE_1500_02);
        [$first] = $this->seedTrackerlessRelay($round, [PuzzleFixture::PUZZLE_1500_01, PuzzleFixture::PUZZLE_1500_02], 'Relay Rockets', 5025, size: 3);
        self::getContainer()->get(\Symfony\Component\Messenger\MessageBusInterface::class)->dispatch(new \SpeedPuzzling\Web\Message\ClaimEventResultSlot($first->id->toString(), self::OWNER));

        $this->assertOnlyTheFriendSees($browser, '/en/events/' . self::TRACKERLESS_EVENT_SLUG . '/results/' . self::TRACKERLESS_ROUND_SLUG, self::OWNER_NAME);
    }

    public function testHerUnfinishedResultOnThePuzzlePageNamesHerForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $this->seedUnfinishedResult(self::OWNER, PuzzleFixture::PUZZLE_1500_01, 1234);

        $this->assertOnlyTheFriendSees($browser, '/en/puzzle/' . PuzzleFixture::PUZZLE_1500_01, self::OWNER_NAME);
        self::assertStringContainsString('data-testid="unfinished-row"', (string) $browser->getResponse()->getContent());
    }

    private function assertOnlyTheFriendSees(KernelBrowser $browser, string $url, string $needle): void
    {
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
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (gen_random_uuid(), :owner, :friend, " . TestClock::SQL_NOW . ", 'admin')",
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
            'UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
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
     * The public standings (competition toolkit A7): her row names her by the organiser's name only ("Gina Quick") - no
     * profile name, link, #code or id - except for the friend, and the same in a pair's row (Edge Hunters).
     */
    public function testAPublicStandingLinksHerProfileForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id IN (:b, :pairs)', ['b' => OfficialResultsFixture::ROUND_GROUP_B, 'pairs' => OfficialResultsFixture::ROUND_PAIRS]);
        PublishedStanding::insert($database, $cup, 'overall-solo', 'Overall solo', 'solo', [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B]);
        PublishedStanding::insert($database, $cup, 'overall-pairs', 'Overall pairs', 'duo', [OfficialResultsFixture::ROUND_PAIRS]);
        $profileLink = 'href="/en/player-profile/' . self::OWNER . '"';
        $pages = ['/en/events/results-cup/standings/overall-solo', '/en/events/results-cup/standings/overall-pairs'];

        foreach ([null, ...array_values(self::STRANGERS)] as $viewer) {
            $browser->restart();
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            foreach ($pages as $url) {
                $content = $this->get($browser, $url);
                self::assertStringContainsString('Gina Quick', $content, 'No canary: ' . $url);
                self::assertStringNotContainsString($profileLink, $content, 'A stranger is shown a private player: ' . $url);
                self::assertStringNotContainsString(self::OWNER_NAME, $content, $url);
                self::assertStringNotContainsString(self::OWNER, $content, $url);
                self::assertStringNotContainsString('PLAYER2', $content, $url);
            }
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        foreach ($pages as $url) {
            self::assertStringContainsString($profileLink, $this->get($browser, $url), 'The allowed friend does not see her profile here - the page is no canary: ' . $url);
        }
    }

    /**
     * The public bracket (competition toolkit A9): her qualified row in Group B and in the pairs round (Edge Hunters)
     * names her by the organiser's name only ("Gina Quick") - no profile name, link, #code or id - except for the friend.
     */
    public function testTheBracketLinksHerProfileForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id IN (:b, :pairs)', ['b' => OfficialResultsFixture::ROUND_GROUP_B, 'pairs' => OfficialResultsFixture::ROUND_PAIRS]);
        DeclaredAdvancementStep::insert($database, $cup, [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], [OfficialResultsFixture::ROUND_FINAL], qualifyCount: 2);
        DeclaredAdvancementStep::insert($database, $cup, [OfficialResultsFixture::ROUND_PAIRS], [OfficialResultsFixture::ROUND_PAIRS_FINAL]);
        $profileLink = 'href="/en/player-profile/' . self::OWNER . '"';
        $url = '/en/events/results-cup/bracket';

        foreach ([null, ...array_values(self::STRANGERS)] as $viewer) {
            $browser->restart();
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            $content = $this->get($browser, $url);
            self::assertStringContainsString('Gina Quick', $content, 'No canary: the bracket does not list her');
            self::assertStringContainsString('Edge Hunters', $content, 'No canary: the bracket does not list her pair');
            self::assertStringNotContainsString($profileLink, $content, 'A stranger is shown a private player');
            self::assertStringNotContainsString(self::OWNER_NAME, $content);
            self::assertStringNotContainsString(self::OWNER, $content);
            self::assertStringNotContainsString('PLAYER2', $content);
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString($profileLink, $this->get($browser, $url), 'The allowed friend does not see her profile here - the page is no canary');
    }

    /**
     * The public competitor pages (competition toolkit B4): her person page and the table she sits at name her by the
     * organiser's name only ("Gina Quick") - no profile name, link, #code or id - except for the friend; the state JSON
     * never carries a player id or code at all.
     */
    public function testHerCompetitorPageLinksHerProfileForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE competition SET competitor_pages = true WHERE id = :id', ['id' => $cup]);
        $profileLink = 'href="/en/player-profile/' . self::OWNER . '"';
        $pages = ['/en/competitor/' . $cup . '/p/' . OfficialResultsFixture::PARTICIPANT_GINA, '/en/competitor/' . $cup . '/t/1'];
        $state = '/competitor-state/' . $cup . '/p/' . OfficialResultsFixture::PARTICIPANT_GINA;

        foreach ([null, ...array_values(self::STRANGERS)] as $viewer) {
            $browser->restart();
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            foreach ([...$pages, $state] as $url) {
                $content = $this->get($browser, $url);
                self::assertStringContainsString('Gina Quick', $content, 'No canary: ' . $url);
                self::assertStringNotContainsString($profileLink, $content, 'A stranger is shown a private player: ' . $url);
                self::assertStringNotContainsString(self::OWNER_NAME, $content, $url);
                self::assertStringNotContainsString(self::OWNER, $content, $url);
                self::assertStringNotContainsString('#PLAYER2', $content, $url);
            }
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        foreach ($pages as $url) {
            self::assertStringContainsString($profileLink, $this->get($browser, $url), 'The allowed friend does not see her profile here - the page is no canary: ' . $url);
        }
        self::assertStringContainsString('#PLAYER2', $this->get($browser, $pages[0]), 'her code, for the friend');
        self::assertStringNotContainsString(self::OWNER, $this->get($browser, $state), 'the JSON names people only');
    }

    /**
     * The same on a `pick` round's page (competition toolkit A5): her row with its chosen puzzles names her by the
     * organiser's name only (OfficialEntrantsView), except for the friend.
     */
    public function testAPickRoundsPublishedOfficialResultsLinkHerProfileForTheFriendOnly(): void
    {
        $browser = self::createClient();
        $url = '/en/events/results-cup/results/group-b';
        $profileLink = 'href="/en/player-profile/' . self::OWNER . '"';
        $database = self::getContainer()->get(Connection::class);
        $roundPuzzleId = $database->fetchOne('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => OfficialResultsFixture::ROUND_GROUP_B]);
        $database->executeStatement(
            "UPDATE competition_round SET puzzle_format = 'pick', puzzles_to_solve = 1, results_published_at = " . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_GROUP_B],
        );
        $database->executeStatement(
            'UPDATE competition_participant_round SET result_puzzles = :puzzles WHERE round_id = :round',
            ['puzzles' => json_encode([['roundPuzzleId' => $roundPuzzleId, 'done' => true]], JSON_THROW_ON_ERROR), 'round' => OfficialResultsFixture::ROUND_GROUP_B],
        );

        $content = $this->get($browser, $url);
        self::assertStringContainsString('Gina Quick', $content, 'No canary: the official results do not list her.');
        self::assertStringContainsString('data-official-entry-puzzles', $content, 'No canary: the rows show no chosen puzzles.');
        self::assertStringNotContainsString($profileLink, $content, 'A guest is shown a private player.');
        self::assertStringNotContainsString(self::OWNER_NAME, $content, 'A guest is shown a private player.');

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $content = $this->get($browser, $url);
            self::assertStringNotContainsString($profileLink, $content, "A signed-in stranger ({$who}) is shown a private player.");
            self::assertStringNotContainsString(self::OWNER_NAME, $content, "A signed-in stranger ({$who}) is shown a private player.");
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        self::assertStringContainsString($profileLink, $this->get($browser, $url), 'The allowed friend does not see her profile here - the page is no canary.');
    }

    /**
     * A competition display (timer screen / stream overlay, toolkit D-displays-overlays.md §9.4) with live results of
     * Group B lists her by the organiser's name ("Gina Quick") and nothing of her profile - for everybody, the friend
     * included: a display has no viewer (an anonymous capability URL, the stateless firewall), so no allow list unmasks.
     */
    public function testACompetitionDisplayNeverNamesHer(): void
    {
        $browser = self::createClient();
        $token = 'DispPrivate11111111111';
        self::getContainer()->get(Connection::class)->insert('competition_display', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'kind' => 'screen',
            'name' => 'Hall',
            'token' => $token,
            'locale' => 'en',
            'design' => '{"schema": 1, "elements": [{"id": "board001", "type": "leaderboard", "hidden": false, "when": {"phases": ["idle"]}, "props": {"rows": 10, "source": "live"}}, {"id": "ticker01", "type": "ticker", "hidden": false, "when": {"phases": ["idle"]}, "props": {"rows": 10, "source": "live"}}]}',
            'created_at' => TestClock::FROZEN_NOW,
            'updated_at' => TestClock::FROZEN_NOW,
            'follow_mode' => 'pinned',
            'pinned_round_id' => OfficialResultsFixture::ROUND_GROUP_B,
            'uses_results' => true,
            'uses_live_results' => true,
        ], ['uses_results' => 'boolean', 'uses_live_results' => 'boolean']);
        // The state answer and the display page (toolkit D4), which carries the same state inline (as an HTML
        // attribute - read decoded, so an escaped name could not slip past)
        $urls = ['/display/' . $token . '/state', '/display/' . $token];
        $read = fn (string $url): string => html_entity_decode($this->get($browser, $url), ENT_QUOTES | ENT_HTML5);

        foreach ($urls as $url) {
            $content = $read($url);
            self::assertStringContainsString('Gina Quick', $content, "No canary: the display does not list her ({$url}).");
            self::assertStringNotContainsString(self::OWNER_NAME, $content, "A guest is shown a private player ({$url}).");
            self::assertStringNotContainsString(self::OWNER, $content);
        }

        foreach ([...self::STRANGERS, 'friend' => self::FRIEND] as $who => $viewerId) {
            TestingLogin::asPlayer($browser, $viewerId);
            // The first request after loginUser() carries the test's token in the container - the second is a browser's
            $read($urls[0]);

            foreach ($urls as $url) {
                $content = $read($url);
                self::assertStringContainsString('Gina Quick', $content);
                self::assertStringNotContainsString(self::OWNER_NAME, $content, "A display names a private player ({$who}, {$url}).");
                self::assertStringNotContainsString(self::OWNER, $content, "A display carries a private player's id ({$who}, {$url}).");
                self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
            }
        }
    }

    /**
     * The analytics studio (docs/features/competitions-management/analytics-studio.md D3, R16): she is an entrant of
     * Group B ("Gina Quick") and the fastest solver of its puzzle - by the organiser's name, never analysable, and a
     * nameless record holder; nothing of her profile, her id or her history in any answer, for everybody, the friend
     * included (no viewer - no allow list unmasks).
     */
    public function testTheAnalyticsStudioNeverNamesHer(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, puzzling_type, puzzlers_count)
             VALUES (:id, :player, :puzzle, 1234, TIMESTAMP '2026-09-01 10:00:00', TIMESTAMP '2026-09-01 10:00:00', true, true, 'solo', 1)",
            ['id' => Uuid::uuid7()->toString(), 'player' => self::OWNER, 'puzzle' => PuzzleFixture::PUZZLE_5000],
        );
        $base = '/analytics-studio/' . OfficialResultsFixture::ROUND_GROUP_B;
        $urls = [$base . '/state', $base . '/analytics', $base . '/entrant/' . OfficialResultsFixture::ENTRY_B_GINA, $base . '/predictions?entries=' . OfficialResultsFixture::ENTRY_B_GINA];

        foreach ([null, ...array_values(self::STRANGERS), self::FRIEND] as $viewer) {
            $browser->restart();
            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }
            $this->clearAnalyticsStudioCache();

            $state = $this->get($browser, $urls[0]);
            self::assertStringContainsString('Gina Quick', $state, 'No canary: the studio does not list her.');
            self::assertStringContainsString('"seconds":1234', $this->get($browser, $urls[1]), 'No canary: she is not among the record holders.');

            foreach ($urls as $url) {
                $content = $this->get($browser, $url);
                self::assertStringNotContainsString(self::OWNER_NAME, $content, ($viewer ?? 'guest') . ' ' . $url);
                self::assertStringNotContainsString(self::OWNER, $content, ($viewer ?? 'guest') . ' ' . $url);
            }
        }
    }

    /**
     * The partner finder (docs/features/partner-finder.md D5): listing herself shows a private player's name, flag and
     * listing to every signed-in viewer - never to guests, and her numbers and profile link only to the friend.
     */
    public function testPartnerFinderShowsAPrivateListerWithoutNumbersOrLinkToStrangers(): void
    {
        $browser = self::createClient();
        $url = '/en/events/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG . '/partners';
        $profileLink = '/en/player-profile/' . self::OWNER;
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            "INSERT INTO partner_listing (id, competition_id, player_id, group_size, looking_for, co_member_ids, strengths, custom_strengths, languages, created_at, updated_at)
             VALUES (:id, :competition, :player, 1, '[\"pair\"]', '[]', '[]', '[]', '[\"en\"]', " . TestClock::SQL_NOW . ", " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'competition' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, 'player' => self::OWNER],
        );
        $connection->executeStatement(
            "INSERT INTO community_player_stats (player_id, solved_total, pieces_total, solves7d, pieces7d, solves30d, solves_prev30d, solves_this_month, pieces_this_month, solves_last_month, pieces_last_month, best500_seconds, best1000_seconds, median500_seconds, monthly_solves, favorites_count, computed_at)
             VALUES (:player, 77, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2345, NULL, 2345, '[]', 0, " . TestClock::SQL_NOW . ")",
            ['player' => self::OWNER],
        );
        $bestTime = 'data-median500="2345"';

        self::assertStringNotContainsString(self::OWNER_NAME, $this->get($browser, $url), 'A guest is shown a private player.');

        foreach (self::STRANGERS as $who => $strangerId) {
            TestingLogin::asPlayer($browser, $strangerId);
            $content = $this->get($browser, $url);
            self::assertStringContainsString(self::OWNER_NAME, $content, "Her listing shows her name ({$who}).");
            self::assertStringNotContainsString($profileLink, $content, "A signed-in stranger ({$who}) gets her profile link.");
            self::assertStringNotContainsString($bestTime, $content, "A signed-in stranger ({$who}) sees her numbers.");
        }

        TestingLogin::asPlayer($browser, self::FRIEND);
        $content = $this->get($browser, $url);
        self::assertStringContainsString($profileLink, $content, 'The allowed friend does not see her profile link - the page is no canary.');
        self::assertStringContainsString($bestTime, $content);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    private function get(KernelBrowser $browser, string $url): string
    {
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return (string) $browser->getResponse()->getContent();
    }
}
