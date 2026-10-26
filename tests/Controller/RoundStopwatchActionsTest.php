<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\Update;

/**
 * The round stopwatch's actions (AbstractRoundStopwatchController): every form needs the stateless CSRF token, the open
 * pages learn the new state from one public update on `/round-stopwatch/{roundId}` sent after the dispatch returned
 * (RoundStopwatchLiveUpdates - the state read back from the database), a refusal changes nothing and is said, and the
 * online control room may ask for JSON.
 */
final class RoundStopwatchActionsTest extends WebTestCase
{
    private const string ROUND = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;
    private const string TOPIC = '/round-stopwatch/' . self::ROUND;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
    }

    public function testStartRunsTheClockAndTellsEveryPageAfterTheWrite(): void
    {
        $this->post('/en/start-round-stopwatch/' . self::ROUND);

        self::assertResponseRedirects('/en/round-stopwatch/' . self::ROUND);
        self::assertSame('running', $this->column('stopwatch_status'));

        $update = $this->onlyUpdate();
        self::assertSame([self::TOPIC], $update->getTopics());
        self::assertFalse($update->isPrivate(), 'the stopwatch topic is public - projector pages and phones without an account');

        $payload = self::payload($update);
        self::assertSame(self::ROUND, $payload['roundId']);
        self::assertSame('running', $payload['status']);
        self::assertSame(60, $payload['minutesLimit']);
        self::assertIsString($payload['startedAt']);
        self::assertIsInt($payload['startedAtMs']);
        self::assertSame(new DateTimeImmutable($payload['startedAt'])->getTimestamp() * 1000, $payload['startedAtMs']);
        self::assertNull($payload['stoppedAt']);
        self::assertNull($payload['stoppedAtMs']);
        self::assertIsInt($payload['asOfMs']);
        self::assertIsString($payload['stateHash']);
        self::assertArrayNotHasKey('type', $payload, 'the live entry tells stopwatch updates by the missing type');
    }

    public function testStopAndResetTellThePagesToo(): void
    {
        $this->post('/en/start-round-stopwatch/' . self::ROUND);
        $this->post('/en/stop-round-stopwatch/' . self::ROUND);

        self::assertResponseRedirects('/en/manage-round-stopwatch/' . self::ROUND);
        self::assertSame('stopped', $this->column('stopwatch_status'));

        $stopped = self::payload($this->onlyUpdate());
        self::assertSame('stopped', $stopped['status']);
        self::assertIsInt($stopped['stoppedAtMs']);

        $this->post('/en/reset-round-stopwatch/' . self::ROUND);

        self::assertResponseRedirects('/en/manage-round-stopwatch/' . self::ROUND);
        self::assertNull($this->column('stopwatch_status'));

        $reset = self::payload($this->onlyUpdate());
        self::assertNull($reset['status']);
        self::assertNull($reset['startedAtMs']);
        self::assertNotSame($stopped['stateHash'], $reset['stateHash']);
    }

    public function testWithoutTheTokenNothingHappens(): void
    {
        foreach (['start', 'stop', 'reset'] as $action) {
            $this->browser->request('POST', '/en/' . $action . '-round-stopwatch/' . self::ROUND, [], [], ['HTTP_ORIGIN' => 'http://localhost']);

            self::assertResponseRedirects('/en/manage-round-stopwatch/' . self::ROUND);
            self::assertNull($this->column('stopwatch_status'));
            self::assertSame([], self::hub()->getPublishedUpdates());
        }

        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert', 'open too long');
    }

    public function testAForeignPageCannotPostTheForm(): void
    {
        $this->browser->request('POST', '/en/start-round-stopwatch/' . self::ROUND, ['_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseRedirects('/en/manage-round-stopwatch/' . self::ROUND);
        self::assertNull($this->column('stopwatch_status'));
    }

    public function testTheControlRoomGetsJson(): void
    {
        $this->post('/en/start-round-stopwatch/' . self::ROUND, json: true);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->browser->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($this->browser->getResponse()->headers->hasCacheControlDirective('private'));
        $answer = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsArray($answer['stopwatch']);
        self::assertSame('running', $answer['stopwatch']['status']);
        self::assertSame(self::payload($this->onlyUpdate())['stateHash'], $answer['stopwatch']['stateHash']);

        $this->browser->request('POST', '/en/stop-round-stopwatch/' . self::ROUND, [], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('{"error":"invalid_csrf"}', $this->browser->getResponse()->getContent());
        self::assertSame('running', $this->column('stopwatch_status'));
    }

    /**
     * A Reset of an online round starts a fresh run - refused while the round has official results: said on the
     * control page, `results_confirmed` 409 as JSON, nothing changes
     */
    public function testAResetOverAnOnlineRoundsResultsIsRefusedAndSaid(): void
    {
        $online = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
        $connection = self::getContainer()->get(Connection::class);
        $this->post('/en/start-round-stopwatch/' . $online);
        $connection->executeStatement('UPDATE competition_participant_round SET result_seconds = 1234 WHERE id = :id', ['id' => OnlineRoundsFixture::ENTRY_CONNECTED]);

        $this->post('/en/reset-round-stopwatch/' . $online);

        self::assertResponseRedirects('/en/manage-round-stopwatch/' . $online);
        self::assertSame([], self::hub()->getPublishedUpdates());
        self::assertSame('running', $connection->fetchOne('SELECT stopwatch_status FROM competition_round WHERE id = :id', ['id' => $online]));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert', 'Clear them at the results desk first');

        $this->post('/en/reset-round-stopwatch/' . $online, json: true);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('{"error":"results_confirmed"}', $this->browser->getResponse()->getContent());
    }

    /**
     * "End now" ends an online round for good (docs/features/online-competitions/README.md §5.1): Start - now, in 10 s
     * or at a set time - is refused and said (`ended_use_reset` 409 as JSON, a flash on the control page), nothing is
     * written or published; the pages offer no Start while it is stopped, only Reset
     */
    public function testAnEndedOnlineRoundIsNotStartedAgain(): void
    {
        $online = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
        $connection = self::getContainer()->get(Connection::class);
        $this->post('/en/start-round-stopwatch/' . $online);
        $this->post('/en/stop-round-stopwatch/' . $online);
        $before = $connection->fetchAssociative('SELECT stopwatch_status, stopwatch_started_at, stopwatch_stopped_at FROM competition_round WHERE id = :id', ['id' => $online]);
        self::assertIsArray($before);
        self::assertSame('stopped', $before['stopwatch_status']);

        $this->post('/en/start-round-stopwatch/' . $online);

        self::assertResponseRedirects('/en/manage-round-stopwatch/' . $online);
        self::assertSame([], self::hub()->getPublishedUpdates());
        self::assertSame($before, $connection->fetchAssociative('SELECT stopwatch_status, stopwatch_started_at, stopwatch_stopped_at FROM competition_round WHERE id = :id', ['id' => $online]));
        $page = $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert', 'This online round has ended - Reset it to run it again.');
        // The page offers Reset, not Start
        self::assertSame('idle', $page->filter('form[action$="/en/start-round-stopwatch/' . $online . '"]')->closest('[data-stopwatch-when]')?->attr('data-stopwatch-when'));
        self::assertNotNull($page->filter('form[action$="/en/start-round-stopwatch/' . $online . '"]')->closest('[data-stopwatch-when]')->attr('hidden'));
        self::assertNull($page->filter('form[action$="/en/reset-round-stopwatch/' . $online . '"]')->closest('[data-stopwatch-when]')?->attr('hidden'));
        $projector = $this->browser->request('GET', '/en/round-stopwatch/' . $online);
        self::assertNotNull($projector->filter('form[action$="/en/start-round-stopwatch/' . $online . '"]')->closest('[data-stopwatch-when]')?->attr('hidden'));

        $this->post('/en/start-round-stopwatch/' . $online, json: true);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('{"error":"ended_use_reset"}', $this->browser->getResponse()->getContent());

        // The control room's "Start in 10 s"
        self::hub()->reset();
        $this->browser->request('POST', '/en/round-stopwatch/' . $online . '/schedule', ['_token' => 'csrf-token', 'countdown' => '10'], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('{"error":"ended_use_reset"}', $this->browser->getResponse()->getContent());
        self::assertSame([], self::hub()->getPublishedUpdates());
        self::assertSame($before, $connection->fetchAssociative('SELECT stopwatch_status, stopwatch_started_at, stopwatch_stopped_at FROM competition_round WHERE id = :id', ['id' => $online]));

        // A Reset runs it again
        $this->post('/en/reset-round-stopwatch/' . $online);
        $this->post('/en/start-round-stopwatch/' . $online);
        self::assertResponseRedirects('/en/round-stopwatch/' . $online);
        self::assertSame('running', $connection->fetchOne('SELECT stopwatch_status FROM competition_round WHERE id = :id', ['id' => $online]));
    }

    /**
     * In person Stop is a pause and Start resumes it - unchanged, and the pages keep offering Start while stopped
     */
    public function testAStoppedInPersonRoundIsResumed(): void
    {
        $this->post('/en/start-round-stopwatch/' . self::ROUND);
        $this->post('/en/stop-round-stopwatch/' . self::ROUND);

        $page = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);
        self::assertSame('idle stopped', $page->filter('form[action$="/en/start-round-stopwatch/' . self::ROUND . '"]')->closest('[data-stopwatch-when]')?->attr('data-stopwatch-when'));
        self::assertNull($page->filter('form[action$="/en/start-round-stopwatch/' . self::ROUND . '"]')->closest('[data-stopwatch-when]')->attr('hidden'));
        $projector = $this->browser->request('GET', '/en/round-stopwatch/' . self::ROUND);
        self::assertNull($projector->filter('form[action$="/en/start-round-stopwatch/' . self::ROUND . '"]')->closest('[data-stopwatch-when]')?->attr('hidden'));

        $this->post('/en/start-round-stopwatch/' . self::ROUND);

        self::assertResponseRedirects('/en/round-stopwatch/' . self::ROUND);
        self::assertSame('running', $this->column('stopwatch_status'));
        self::assertNull($this->column('stopwatch_stopped_at'));
        self::assertSame('running', self::payload($this->onlyUpdate())['status']);
    }

    public function testANonOrganiserIsDenied(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->post('/en/start-round-stopwatch/' . self::ROUND);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->column('stopwatch_status'));
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    private function post(string $url, bool $json = false): void
    {
        self::hub()->reset();

        $server = ['HTTP_ORIGIN' => 'http://localhost'];

        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        $this->browser->request('POST', $url, ['_token' => 'csrf-token'], [], $server);
    }

    private function onlyUpdate(): Update
    {
        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);

        return $updates[0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Update $update): array
    {
        $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function column(string $column): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne(
            sprintf('SELECT %s FROM competition_round WHERE id = :id', $column),
            ['id' => self::ROUND],
        );
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
