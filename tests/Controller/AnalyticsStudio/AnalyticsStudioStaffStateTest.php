<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The analytics studio for the event's staff (analytics-studio.md "FU-ST"): signed-in organisers and referees always see
 * the live ranking of an unpublished round on `/analytics-studio/{roundId}/staff-state`, whatever the competitor pages'
 * live results; everybody else - signed out, a signed-in stranger, the public state - keeps FU-R2. The staff answer has
 * its own cache entry and never reaches the public one.
 *
 * "Results Cup": organiser PLAYER_WITH_STRIPE; Group B not published (Gina 3900 s - PLAYER_PRIVATE -, Hugo 4500 s,
 * Ivan 5000 s); competitor pages and their live results off in the fixture.
 */
final class AnalyticsStudioStaffStateTest extends WebTestCase
{
    use AnalyticsStudioTesting;

    private const string STAFF_STATE = '/analytics-studio/' . F::ROUND_GROUP_B . '/staff-state';
    private const string PUBLIC_STATE = '/analytics-studio/' . F::ROUND_GROUP_B . '/state';
    private const string PAGE = '/en/events/results-cup/analytics-studio/group-b';
    private const array RANKING = [F::ENTRY_B_GINA, F::ENTRY_B_HUGO, F::ENTRY_B_IVAN];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    /**
     * @return iterable<string, array{null|string, bool, bool}>
     */
    public static function viewers(): iterable
    {
        // viewer, live results setting on, sees the live ranking
        yield 'organiser, setting off' => [PlayerFixture::PLAYER_WITH_STRIPE, false, true];
        yield 'organiser, setting on' => [PlayerFixture::PLAYER_WITH_STRIPE, true, true];
        yield 'referee, setting off' => ['referee', false, true];
        yield 'referee, setting on' => ['referee', true, true];
        yield 'signed out, setting off' => [null, false, false];
        yield 'signed out, setting on' => [null, true, true];
        yield 'stranger, setting off' => [PlayerFixture::PLAYER_REGULAR, false, false];
        yield 'stranger, setting on' => [PlayerFixture::PLAYER_REGULAR, true, true];
    }

    #[DataProvider('viewers')]
    public function testWhoSeesTheLiveRankingOfAnUnpublishedRound(null|string $viewer, bool $settingOn, bool $seesRanking): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();
        $this->addReferee();

        if ($settingOn) {
            $this->liveResults(true);
        }

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer === 'referee' ? PlayerFixture::PLAYER_WITH_FAVORITES : $viewer);
        }

        $state = $this->studioJson($browser, self::STAFF_STATE);
        self::assertSame($seesRanking ? self::RANKING : [], self::column(self::dig($state, 'results'), 'key'));

        if ($seesRanking) {
            self::assertSame([1, 2, 3], self::column(self::dig($state, 'results'), 'rank'));
            self::assertSame([3900, 4500, 5000], self::column(self::dig($state, 'results'), 'seconds'));
            self::assertSame(['official', 'official', 'official'], self::column(self::dig($state, 'results'), 'state'), 'no official/unofficial labels');
        }

        // The page of the same viewer: staff read the staff state (with their session), anybody else the public one
        $isStaff = $viewer !== null && $viewer !== PlayerFixture::PLAYER_REGULAR;
        $root = $browser->request('GET', self::PAGE)->filter('[data-analytics-studio]');
        self::assertResponseIsSuccessful();
        self::assertSame($isStaff ? self::STAFF_STATE : self::PUBLIC_STATE, $root->attr('data-state-url'));
        self::assertSame($isStaff ? '1' : null, $root->attr('data-state-session'));

        // The public state is FU-R2 for every viewer: the ranking only while the setting is on
        $public = $this->studioJson($browser, self::PUBLIC_STATE);
        self::assertSame($settingOn ? self::RANKING : [], self::column(self::dig($public, 'results'), 'key'));
    }

    public function testTheStaffAnswerNeverReachesThePublicCache(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Staff first - the 2 s entry of the staff state is filled
        self::assertSame(self::RANKING, self::column(self::dig($this->studioJson($browser, self::STAFF_STATE), 'results'), 'key'));

        // Then the public state within the same 2 s (same stopwatch, puzzles, publication, setting): nothing
        self::assertSame([], self::dig($this->studioJson($browser, self::PUBLIC_STATE), 'results'));
        // And signed out on the staff URL: the public answer, not the cached staff one
        $browser->getCookieJar()->clear();
        self::assertSame([], self::dig($this->studioJson($browser, self::STAFF_STATE), 'results'));

        // The other way round: a public entry never answers staff
        $this->clearAnalyticsStudioCache();
        self::assertSame([], self::dig($this->studioJson($browser, self::PUBLIC_STATE), 'results'));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(self::RANKING, self::column(self::dig($this->studioJson($browser, self::STAFF_STATE), 'results'), 'key'));
    }

    public function testTheStaffAnswerIsNeverStoredAndMarksTheRoundStaffWatched(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $state = $this->studioJson($browser, self::STAFF_STATE);
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl);
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));

        self::assertSame(TestClock::milliseconds(), self::dig($state, 'serverNowMs'));
        self::assertSame(['/round-stopwatch/' . F::ROUND_GROUP_B, '/analytics-studio-signal/' . F::ROUND_GROUP_B], self::dig($state, 'mercure', 'topics'), 'the same public topics');
        self::assertTrue($this->studioWatch()->watched(F::ROUND_GROUP_B));
        self::assertTrue($this->studioWatch()->staffWatched(F::ROUND_GROUP_B));

        // Signed out on the staff URL: the public answer, the same headers, no staff marker
        $this->clearAnalyticsStudioCache();
        $browser->getCookieJar()->clear();
        $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_A . '/staff-state');
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertFalse($this->studioWatch()->staffWatched(F::ROUND_GROUP_A));
    }

    public function testAStaffMembersAllowListNeverUnmasksAPrivatePlayer(): void
    {
        // PLAYER_WITH_FAVORITES is on Gina's (PLAYER_PRIVATE) allow list - as a referee their state is still everybody's
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();
        $this->addReferee();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $state = $this->studioJson($browser, self::STAFF_STATE);
        $members = [];
        foreach (self::digArray($state, 'entrants') as $entrant) {
            $members = [...$members, ...self::digArray($entrant, 'members')];
        }

        self::assertSame(['name' => 'Gina Quick', 'country' => 'us', 'profile' => null, 'code' => null, 'analysable' => false], self::dig(self::keyed($members, 'name'), 'Gina Quick'));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, (string) $browser->getResponse()->getContent());
    }

    public function testNothingOfAnEventThatIsNotPublicForItsStaffEither(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->studioDatabase()->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);

        $this->studioJson($browser, self::STAFF_STATE, 404);
    }

    private function addReferee(): void
    {
        $this->studioDatabase()->executeStatement(
            'INSERT INTO competition_referee (id, competition_id, player_id, added_by_id, added_at) VALUES (:id, :competition, :player, NULL, :at)',
            ['id' => Uuid::uuid7()->toString(), 'competition' => F::COMPETITION_RESULTS_CUP, 'player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'at' => TestClock::now()->format('Y-m-d H:i:s')],
        );
    }

    private function liveResults(bool $on): void
    {
        $this->studioDatabase()->executeStatement(
            'UPDATE competition SET competitor_pages = :on, competitor_pages_live_results = :on WHERE id = :id',
            ['on' => $on ? 'true' : 'false', 'id' => F::COMPETITION_RESULTS_CUP],
        );
    }
}
