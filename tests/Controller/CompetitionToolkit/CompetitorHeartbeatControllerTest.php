<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitorHeartbeatToken;
use SpeedPuzzling\Web\Services\CompetitionToolkit\InMemoryCompetitorHeartbeats;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals\TimeProposalScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CompetitorHeartbeat;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * `POST /-/competitor-heartbeat` (reliability hardening T2, competitor-pages.md "Timing you can trust"): the token from
 * the competitor state is the only right; no session, no Postgres; the beat lands in CompetitorHeartbeats and a later
 * proposal of the entry is judged against it.
 */
final class CompetitorHeartbeatControllerTest extends WebTestCase
{
    use QueryCountAssertions;
    use TimeProposalScenario;

    private const string URL = '/-/competitor-heartbeat';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        self::heartbeats()->forget();
    }

    public function testABeatIsStoredAndAnsweredWithTheServersTimeWithoutAnyStatement(): void
    {
        $this->startCountingQueries($this->browser);
        $this->beat(['token' => self::token(), 'atMs' => TestClock::milliseconds() - 40, 'offsetMs' => -41.5, 'rttMs' => 90, 'sampleAgeMs' => 3000, 'unsent' => 0, 'oldestUnsentTapMs' => null]);

        self::assertResponseIsSuccessful();
        self::assertSame(['serverNowMs' => TestClock::milliseconds()], $this->json());
        self::assertSame([], $this->executedSql($this->browser), 'A heartbeat never touches Postgres');
        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);

        $record = self::heartbeats()->of(OfficialResultsFixture::ROUND_FINAL, self::ANNA_FINAL, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($record);
        self::assertSame(TestClock::milliseconds(), $record->lastHeardAtMs);
        self::assertSame(-41.5, $record->lastOffsetMs);
        // Clean: the evidence moment is the phone's own
        self::assertSame(TestClock::milliseconds() - 40, $record->cleanAtMs);
        self::assertSame(TestClock::milliseconds(), $record->cleanHeardAtMs);
    }

    public function testABeatWithAnUnsentTapIsNoEvidence(): void
    {
        $this->beat(['token' => self::token(), 'atMs' => TestClock::milliseconds(), 'unsent' => 1, 'oldestUnsentTapMs' => TestClock::milliseconds() - 30_000]);

        self::assertResponseIsSuccessful();
        $record = self::heartbeats()->of(OfficialResultsFixture::ROUND_FINAL, self::ANNA_FINAL, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($record);
        self::assertSame(TestClock::milliseconds(), $record->lastHeardAtMs);
        self::assertNull($record->cleanAtMs);
    }

    public function testNoSessionAndNoCookieForABrowserWithCookies(): void
    {
        // The cookies a signed-in browser sends along - the stateless firewall never reads them
        $this->browser->getCookieJar()->set(new Cookie('PHPSESSID', 'some-session'));
        $this->browser->getCookieJar()->set(new Cookie('REMEMBERME', 'some-remember-me'));

        $this->beat(['token' => self::token(), 'unsent' => 0]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->browser->getResponse()->headers->getCookies());
    }

    public function testTheTokenIsTheOnlyRight(): void
    {
        $valid = self::token();
        $cases = [
            'no token' => ['unsent' => 0],
            'garbage' => ['token' => 'nonsense', 'unsent' => 0],
            'another player' => ['token' => str_replace(strtolower(PlayerFixture::PLAYER_ADMIN), strtolower(PlayerFixture::PLAYER_REGULAR), $valid), 'unsent' => 0],
            'another entry' => ['token' => str_replace(strtolower(OfficialResultsFixture::ENTRY_FINAL_ANNA), strtolower(OfficialResultsFixture::TEAM_UNNAMED), $valid), 'unsent' => 0],
            'a longer life' => ['token' => preg_replace('/\.(\d+)\.([0-9a-f]{32})$/', '.9$1.$2', $valid), 'unsent' => 0],
        ];

        foreach ($cases as $label => $body) {
            $this->beat($body);
            self::assertResponseStatusCodeSame(403, $label);
            self::assertSame('invalid_token', $this->json()['error'], $label);
            self::assertSame(TestClock::milliseconds(), $this->json()['serverNowMs'], $label . ': a refusal is a clock sample too');
        }

        self::assertNull(self::heartbeats()->of(OfficialResultsFixture::ROUND_FINAL, self::ANNA_FINAL, PlayerFixture::PLAYER_REGULAR));
    }

    public function testAnExpiredTokenAsksForTheState(): void
    {
        $old = self::getContainer()->get(CompetitorHeartbeatToken::class)->issue(OfficialResultsFixture::ROUND_FINAL, self::ANNA_FINAL, PlayerFixture::PLAYER_ADMIN, TestClock::now()->modify('-7 hours'));

        $this->beat(['token' => $old, 'unsent' => 0]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('expired_token', $this->json()['error']);
    }

    public function testFieldsAndJson(): void
    {
        $this->beat(['token' => self::token()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_fields', $this->json()['error']);

        $this->beat(['token' => self::token(), 'unsent' => -1]);
        self::assertResponseStatusCodeSame(422);

        $this->browser->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: '[1,2]');
        self::assertResponseStatusCodeSame(400);

        $this->browser->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: '{');
        self::assertResponseStatusCodeSame(400);

        $this->browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(405);
    }

    public function testAFloodOfOnePlayerAndRoundIsStopped(): void
    {
        $limiter = self::getContainer()->get('limiter.competitor_heartbeat'); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($limiter instanceof RateLimiterFactoryInterface);
        // A round of its own: the test limiter's counts live on disk across runs (and the clock is frozen)
        $round = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $limiter->create(strtolower(PlayerFixture::PLAYER_REGULAR) . '-' . $round)->consume(100_000);

        $token = self::getContainer()->get(CompetitorHeartbeatToken::class)->issue($round, self::UNNAMED_PAIR, PlayerFixture::PLAYER_REGULAR, TestClock::now());
        $this->beat(['token' => $token, 'unsent' => 0]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->json()['error']);
        self::assertNotNull($this->browser->getResponse()->headers->get('Retry-After'));
    }

    /**
     * End to end: a beat through the endpoint 30 s ago (stored with the frozen clock moved back), the proposal of a tap
     * 50 s ago now - the phone was online without sending: suspicious. The same with the beat reporting the unsent tap:
     * offline, the tap stands.
     */
    public function testAProposalIsJudgedAgainstTheBeats(): void
    {
        self::runningRounds();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        self::heartbeats()->beat(OfficialResultsFixture::ROUND_FINAL, self::ANNA_FINAL, PlayerFixture::PLAYER_ADMIN, new CompetitorHeartbeat(unsent: 0, atMs: TestClock::milliseconds() - 30_000, offsetMs: -41.5, rttMs: 90.0), TestClock::milliseconds() - 30_000);
        $id = $this->propose(50_000);
        self::assertSame('suspicious', self::proposalRow($id)['timing_verdict']);
        self::withdraw($id);

        self::heartbeats()->forget();
        self::heartbeats()->beat(OfficialResultsFixture::ROUND_FINAL, self::ANNA_FINAL, PlayerFixture::PLAYER_ADMIN, new CompetitorHeartbeat(unsent: 1, oldestUnsentTapMs: TestClock::milliseconds() - 50_000, atMs: TestClock::milliseconds() - 30_000), TestClock::milliseconds() - 30_000);
        $id = $this->propose(50_000);
        $row = self::proposalRow($id);
        self::assertSame('offline', $row['timing_verdict']);
        self::assertTrue($row['queued_offline']);
        self::assertSame(3, $row['send_attempts']);
        self::assertSame(2500, $row['offset_sample_age_ms']);
    }

    public function testTheStateHandsTheTokenOnlyToTheCompetitorOnTheirOwnEntry(): void
    {
        self::runningRounds();
        $state = '/competitor-state/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/p/' . OfficialResultsFixture::PARTICIPANT_ANNA;

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', $state);
        self::assertResponseIsSuccessful();
        $final = null;
        $rounds = $this->json()['rounds'];
        self::assertIsArray($rounds);

        foreach ($rounds as $round) {
            if (is_array($round) && $round['roundId'] === OfficialResultsFixture::ROUND_FINAL) {
                $final = $round;
            }
        }

        self::assertIsArray($final);
        self::assertSame(self::token(), $final['heartbeat']);

        $this->browser->restart();
        $this->browser->disableReboot();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $state);
        self::assertStringNotContainsString('"heartbeat"', (string) $this->browser->getResponse()->getContent());
    }

    private function propose(int $tappedAgoMs): string
    {
        $id = self::newProposalId(-$tappedAgoMs);
        $this->browser->request('POST', '/en/competitor-proposals/rounds/' . OfficialResultsFixture::ROUND_FINAL, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode([
            'proposalId' => $id,
            'entry' => self::ANNA_FINAL,
            'tappedAtMs' => TestClock::milliseconds() - $tappedAgoMs,
            'offsetMs' => -41.5,
            'rttMs' => 90,
            'offsetSampleAgeMs' => 2500,
            'queuedOffline' => true,
            'attempts' => 3,
            'sentAtMs' => TestClock::milliseconds(),
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('proposed', $this->json()['status']);

        return $id;
    }

    private static function token(): string
    {
        return self::getContainer()->get(CompetitorHeartbeatToken::class)->issue(OfficialResultsFixture::ROUND_FINAL, self::ANNA_FINAL, PlayerFixture::PLAYER_ADMIN, TestClock::now());
    }

    private static function heartbeats(): InMemoryCompetitorHeartbeats
    {
        return self::getContainer()->get(InMemoryCompetitorHeartbeats::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function beat(array $body): void
    {
        $this->browser->request('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
