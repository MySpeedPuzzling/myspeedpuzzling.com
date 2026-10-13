<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Start in 10 s", "Start at …" and "Cancel the start" on the stopwatch control page (ScheduleRoundStartController,
 * CancelRoundStartController): the countdown is stored as a whole second ahead, a wall time is read in the round's zone,
 * the open pages learn it from one public update after the write, every refusal changes nothing and is said.
 */
final class ScheduleRoundStartControllerTest extends WebTestCase
{
    private const string ROUND = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;
    private const string SCHEDULE = '/en/round-stopwatch/' . self::ROUND . '/schedule';
    private const string CANCEL = '/en/round-stopwatch/' . self::ROUND . '/cancel';
    private const string MANAGE = '/en/manage-round-stopwatch/' . self::ROUND;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
    }

    public function testStartInTenSecondsSchedulesTheNextWholeSecondAtLeastTenSecondsAhead(): void
    {
        $before = $this->now()->getTimestamp();

        $this->post(self::SCHEDULE, ['countdown' => '10']);

        self::assertResponseRedirects(self::MANAGE);
        self::assertSame('scheduled', $this->column('stopwatch_status'));
        $startedAt = $this->column('stopwatch_started_at');
        self::assertIsString($startedAt);
        $startsAt = new DateTimeImmutable($startedAt, new DateTimeZone('UTC'));
        self::assertGreaterThanOrEqual($before + 10, $startsAt->getTimestamp());
        self::assertLessThanOrEqual($this->now()->getTimestamp() + 11, $startsAt->getTimestamp());

        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame(['/round-stopwatch/' . self::ROUND], $updates[0]->getTopics());
        $payload = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('scheduled', $payload['status']);
        self::assertSame($startsAt->getTimestamp() * 1000, $payload['startedAtMs']);
    }

    public function testStartAtReadsTheWallTimeInTheRoundsZone(): void
    {
        $timezone = self::getContainer()->get(CompetitionRoundRepository::class)->get(self::ROUND)->displayTimezone();
        // Two o'clock in the afternoon there, a month ahead - never a daylight-saving change
        $local = $this->now()->setTimezone(new DateTimeZone($timezone))->modify('+30 days')->format('Y-m-d') . 'T14:00:00';

        $this->post(self::SCHEDULE, ['at' => $local]);

        self::assertResponseRedirects(self::MANAGE);
        self::assertSame(
            RoundTimezone::parseLocal($local, 'Y-m-d\TH:i:s', $timezone)->format('Y-m-d H:i:s'),
            $this->column('stopwatch_started_at'),
        );

        // Without seconds, as a datetime-local input without step sends it
        $this->post(self::SCHEDULE, ['at' => substr($local, 0, 16)]);
        self::assertResponseRedirects(self::MANAGE);
        self::assertSame(
            RoundTimezone::parseLocal($local, 'Y-m-d\TH:i:s', $timezone)->format('Y-m-d H:i:s'),
            $this->column('stopwatch_started_at'),
        );
    }

    public function testStartAtWithAnOffsetIsThatMoment(): void
    {
        $this->post(self::SCHEDULE, ['at' => '2030-01-10T10:00:00+01:00']);

        self::assertResponseRedirects(self::MANAGE);
        self::assertSame('2030-01-10 09:00:00', $this->column('stopwatch_started_at'));

        // JavaScript's toISOString(): a fraction counts as the next whole second
        $this->post(self::SCHEDULE, ['at' => '2030-01-10T09:30:00.250Z']);
        self::assertSame('2030-01-10 09:30:01', $this->column('stopwatch_started_at'));
    }

    public function testAMomentThatPassedIsRefusedAndSaid(): void
    {
        $this->post(self::SCHEDULE, ['at' => '2020-01-10T10:00:00+01:00']);

        self::assertResponseRedirects(self::MANAGE);
        self::assertNull($this->column('stopwatch_status'));
        self::assertSame([], self::hub()->getPublishedUpdates());

        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert', 'already passed');
    }

    public function testWhatIsNoMomentIsRefused(): void
    {
        foreach ([['at' => 'tomorrow at seven'], ['at' => ''], ['at' => '2030-02-31T10:00'], ['countdown' => '0'], ['countdown' => '99999'], ['countdown' => 'ten'], []] as $fields) {
            $this->post(self::SCHEDULE, $fields);

            self::assertResponseRedirects(self::MANAGE);
            self::assertNull($this->column('stopwatch_status'), (string) json_encode($fields));
        }

        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert', "time zone");
    }

    public function testTheControlRoomGetsTheRefusalAsJson(): void
    {
        $this->post(self::SCHEDULE, ['at' => '2020-01-10T10:00:00+01:00'], json: true);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('{"error":"start_in_the_past"}', $this->browser->getResponse()->getContent());

        $this->post(self::SCHEDULE, ['countdown' => '10'], json: true);

        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsArray($answer['stopwatch']);
        self::assertSame('scheduled', $answer['stopwatch']['status']);
    }

    public function testCancelCallsTheCountdownOffAndTellsThePages(): void
    {
        $this->post(self::SCHEDULE, ['countdown' => '10']);

        $this->post(self::CANCEL);

        self::assertResponseRedirects(self::MANAGE);
        self::assertNull($this->column('stopwatch_status'));
        self::assertNull($this->column('stopwatch_started_at'));

        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates);
        $payload = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertNull($payload['status']);
        self::assertNull($payload['startedAtMs']);
    }

    public function testACancelAfterTheStartIsRefused(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'scheduled', stopwatch_started_at = :startedAt WHERE id = :id",
            ['startedAt' => $this->now()->modify('-5 seconds')->format('Y-m-d H:i:s'), 'id' => self::ROUND],
        );

        $this->post(self::CANCEL);

        self::assertResponseRedirects(self::MANAGE);
        self::assertSame('scheduled', $this->column('stopwatch_status'));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert', 'can no longer be cancelled');
    }

    public function testEveryFormNeedsTheToken(): void
    {
        $this->browser->request('POST', self::SCHEDULE, ['countdown' => '10'], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects(self::MANAGE);
        self::assertNull($this->column('stopwatch_status'));

        $this->post(self::SCHEDULE, ['countdown' => '10']);
        $this->browser->request('POST', self::CANCEL, [], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertSame('scheduled', $this->column('stopwatch_status'));
    }

    public function testOnlyTheOrganisersMaySchedule(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->post(self::SCHEDULE, ['countdown' => '10']);
        self::assertResponseStatusCodeSame(403);

        $this->post(self::CANCEL);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->column('stopwatch_status'));
    }

    public function testTheControlPageOffersTheCountdownAndThenItsCancel(): void
    {
        $idle = $this->browser->request('GET', self::MANAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $idle->filter('form[action="' . self::SCHEDULE . '"] input[name="countdown"][value="10"]'));
        self::assertCount(1, $idle->filter('form[action="' . self::SCHEDULE . '"] input[type="datetime-local"][name="at"]'));
        self::assertNotNull($idle->filter('form[action="' . self::CANCEL . '"]')->closest('[data-stopwatch-when]')?->attr('hidden'), 'nothing to cancel yet');

        $this->post(self::SCHEDULE, ['countdown' => '10']);
        $scheduled = $this->browser->request('GET', self::MANAGE);

        self::assertNull($scheduled->filter('form[action="' . self::CANCEL . '"]')->closest('[data-stopwatch-when]')?->attr('hidden'));
        self::assertNotNull($scheduled->filter('input[name="countdown"]')->closest('[data-stopwatch-when]')?->attr('hidden'));
        self::assertNotNull($scheduled->filter('input[name="at"]')->closest('[data-stopwatch-when]')?->attr('hidden'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $url, array $fields = [], bool $json = false): void
    {
        self::hub()->reset();

        $server = ['HTTP_ORIGIN' => 'http://localhost'];

        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        $this->browser->request('POST', $url, ['_token' => 'csrf-token', ...$fields], [], $server);
    }

    private function column(string $column): mixed
    {
        $value = self::getContainer()->get(Connection::class)->fetchOne(
            sprintf('SELECT %s FROM competition_round WHERE id = :id', $column),
            ['id' => self::ROUND],
        );

        return $value === false ? null : $value;
    }

    private function now(): DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
