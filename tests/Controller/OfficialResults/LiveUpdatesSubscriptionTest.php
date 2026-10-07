<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The organiser pages follow their rounds with a subscriber token of their own (docs/features/competitions-management/
 * official-results.md "Live updates", review 2 M1): every state carries one for exactly the page's topics, only for
 * whoever passed the voter, and the Mercure subscribe cookie - rewritten by every signed-in response - never carries a
 * round topic any more.
 */
final class LiveUpdatesSubscriptionTest extends WebTestCase
{
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string ROUND = OfficialResultsFixture::ROUND_GROUP_A;

    private const string STATE = '/en/official-results/rounds/' . self::ROUND;
    private const string CHANGES = '/en/official-results/rounds/' . self::ROUND . '/changes';
    private const string LIVE = '/en/live-results/' . self::ROUND;
    private const string DESK = '/en/manage-round-results/' . self::ROUND;
    private const string SEATING = '/en/round-seating/' . self::ROUND;
    private const string OVERVIEW = '/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string COMPETITION_STATE = '/en/official-results/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private const array ROUND_TOPICS = ['/round-results/' . self::ROUND, '/round-stopwatch/' . self::ROUND];

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();

        self::getContainer()->get(Connection::class)->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'player_id' => self::REFEREE,
            'added_by_id' => self::ORGANISER,
            'added_at' => '2026-10-01 10:00:00',
        ]);
    }

    public function testTheRoundStateCarriesATokenForTheRoundAndItsStopwatchOnly(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $before = time();

        $this->browser->request('GET', self::STATE);

        self::assertResponseIsSuccessful();
        $mercure = $this->json()['mercure'] ?? null;
        self::assertIsArray($mercure);
        self::assertSame(self::ROUND_TOPICS, $mercure['topics']);
        self::assertSame(3600, $mercure['expiresIn']);
        self::assertIsString($mercure['url']);
        self::assertStringEndsWith('/.well-known/mercure', $mercure['url']);
        self::assertIsString($mercure['token']);

        $token = self::verified($mercure['token']);
        self::assertSame(['publish' => [], 'subscribe' => self::ROUND_TOPICS], $token->claims()->get('mercure'));
        $expires = $token->claims()->get('exp');
        self::assertInstanceOf(\DateTimeImmutable::class, $expires);
        self::assertGreaterThanOrEqual($before + 3600, $expires->getTimestamp());
        self::assertLessThanOrEqual(time() + 3600, $expires->getTimestamp());
        self::assertSame($expires->format(\DateTimeInterface::ATOM), $mercure['expiresAt']);

        self::assertNoRoundTopicInTheCookie($this->browser);
    }

    public function testEveryOrganiserPageComesWithItsSubscriptionAndLeavesTheCookieAlone(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $crawler = $this->browser->request('GET', self::LIVE);
        self::assertResponseIsSuccessful();
        $state = json_decode($crawler->filter('script[data-live-results-target="initialState"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertSubscription(self::ROUND_TOPICS, $state['mercure'] ?? null);
        self::assertNoRoundTopicInTheCookie($this->browser);

        $crawler = $this->browser->request('GET', self::DESK);
        self::assertResponseIsSuccessful();
        $state = json_decode((string) $crawler->filter('[data-controller="results-desk"]')->attr('data-results-desk-state-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertSubscription(self::ROUND_TOPICS, $state['mercure'] ?? null);
        self::assertNoRoundTopicInTheCookie($this->browser);

        $crawler = $this->browser->request('GET', self::SEATING);
        self::assertResponseIsSuccessful();
        self::assertSubscription(self::ROUND_TOPICS, json_decode((string) $crawler->filter('[data-controller="round-seating"]')->attr('data-round-seating-mercure-value'), true, flags: JSON_THROW_ON_ERROR));
        self::assertNoRoundTopicInTheCookie($this->browser);

        $crawler = $this->browser->request('GET', self::OVERVIEW);
        self::assertResponseIsSuccessful();
        $rounds = [
            OfficialResultsFixture::ROUND_GROUP_A,
            OfficialResultsFixture::ROUND_GROUP_B,
            OfficialResultsFixture::ROUND_PAIRS_FINAL,
        ];
        $overview = json_decode((string) $crawler->filter('[data-controller="results-overview"]')->attr('data-results-overview-mercure-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($overview);
        self::assertIsArray($overview['topics']);
        foreach ($rounds as $roundId) {
            self::assertContains('/round-results/' . $roundId, $overview['topics']);
        }
        self::assertCount(count($crawler->filter('[data-overview-round]')), $overview['topics'], 'one topic per round, nothing else');
        self::assertSubscription(array_values($overview['topics']), $overview);
        self::assertNoRoundTopicInTheCookie($this->browser);

        // The base layout's own subscription (unread counts, conversations) stays as it was
        $topics = json_decode((string) $crawler->filter('[data-controller="mercure-hub"]')->attr('data-mercure-hub-topics-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['/unread-count/' . self::ORGANISER, '/conversations/' . self::ORGANISER], $topics);
    }

    public function testTheOverviewRenewsItsTokenWithEveryRoundsProgress(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', self::COMPETITION_STATE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        $answer = $this->json();
        self::assertIsArray($answer['rounds']);
        $roundIds = array_column($answer['rounds'], 'id');
        self::assertContains(self::ROUND, $roundIds);
        $groupA = $answer['rounds'][array_search(self::ROUND, $roundIds, true)];
        self::assertIsArray($groupA);
        self::assertSame(['total' => 6, 'withTableNumber' => 5, 'withResult' => 5, 'qualified' => 2], $groupA['entries']);

        self::assertSubscription(array_map(static fn (mixed $id): string => '/round-results/' . (is_string($id) ? $id : ''), $roundIds), $answer['mercure'] ?? null);
        self::assertNoRoundTopicInTheCookie($this->browser);
    }

    public function testRecordingAResultLeavesTheCookieAlone(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('POST', self::CHANGES, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode(['changes' => [[
            'clientChangeId' => Uuid::uuid7()->toString(),
            'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP,
            'field' => 'result',
            'from' => null,
            'to' => ['seconds' => 4800],
        ]]], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertNoRoundTopicInTheCookie($this->browser);
    }

    public function testARefereeGetsTheLiveEntrysSubscriptionAndNothingElse(): void
    {
        TestingLogin::asPlayer($this->browser, self::REFEREE);

        $this->browser->request('GET', self::STATE);
        self::assertResponseIsSuccessful();
        self::assertSubscription(self::ROUND_TOPICS, $this->json()['mercure'] ?? null);

        $crawler = $this->browser->request('GET', self::LIVE);
        self::assertResponseIsSuccessful();
        $state = json_decode($crawler->filter('script[data-live-results-target="initialState"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertSubscription(self::ROUND_TOPICS, $state['mercure'] ?? null);

        // The organiser tools - and their tokens - stay closed
        foreach ([self::DESK, self::SEATING, self::OVERVIEW] as $url) {
            $crawler = $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
            self::assertCount(0, $crawler->filter('[data-results-desk-state-value], [data-round-seating-mercure-value], [data-results-overview-mercure-value]'), $url);
        }

        $this->browser->request('GET', self::COMPETITION_STATE);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json());
    }

    /**
     * @return iterable<string, array{null|string, int}>
     */
    public static function nobodyWithRights(): iterable
    {
        yield 'guest' => [null, 401];
        yield 'another player' => [PlayerFixture::PLAYER_REGULAR, 403];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nobodyWithRights')]
    public function testNoTokenForAnybodyWithoutRights(null|string $playerId, int $status): void
    {
        if ($playerId !== null) {
            TestingLogin::asPlayer($this->browser, $playerId);
        }

        foreach ([self::STATE, self::COMPETITION_STATE] as $url) {
            $this->browser->request('GET', $url);

            self::assertResponseStatusCodeSame($status, $url);
            self::assertSame(['error' => $status === 401 ? 'sign_in_required' : 'forbidden'], $this->json(), $url);
        }
    }

    /**
     * @param list<mixed> $topics
     */
    private static function assertSubscription(array $topics, mixed $mercure): void
    {
        self::assertIsArray($mercure);
        self::assertSame($topics, $mercure['topics'] ?? null);
        self::assertIsString($mercure['token'] ?? null);
        self::assertSame(['publish' => [], 'subscribe' => $topics], self::verified($mercure['token'])->claims()->get('mercure'));
    }

    private static function verified(string $jwt): UnencryptedToken
    {
        self::assertNotSame('', $jwt);
        $secret = $_SERVER['MERCURE_JWT_SECRET'] ?? $_ENV['MERCURE_JWT_SECRET'] ?? null;
        self::assertIsString($secret);
        self::assertNotSame('', $secret);

        $configuration = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
        $token = $configuration->parser()->parse($jwt);

        self::assertInstanceOf(UnencryptedToken::class, $token);
        self::assertTrue($configuration->validator()->validate($token, new SignedWith($configuration->signer(), $configuration->verificationKey())), 'signed with the key the hub verifies subscribers with');

        return $token;
    }

    private static function assertNoRoundTopicInTheCookie(KernelBrowser $browser): void
    {
        $found = false;

        foreach ($browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() !== 'mercureAuthorization') {
                continue;
            }

            $found = true;
            $parts = explode('.', (string) $cookie->getValue());
            $payload = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/'), true), true);
            self::assertIsArray($payload);
            self::assertIsArray($payload['mercure'] ?? null);
            self::assertIsArray($payload['mercure']['subscribe'] ?? null);

            foreach ($payload['mercure']['subscribe'] as $topic) {
                self::assertIsString($topic);
                self::assertStringStartsNotWith('/round-results/', $topic);
            }
        }

        self::assertTrue($found, 'the base layout cookie is still written (messaging)');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
