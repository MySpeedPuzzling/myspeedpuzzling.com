<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /round-stopwatch-state/{roundId}` (RoundStopwatchStateController): the fields the pages had (ATOM moments,
 * `serverNow`) plus the milliseconds, when the row was read and a short hash of the stored state - a scheduled start is
 * `scheduled` before its moment and `running` from it, without any write.
 */
final class RoundStopwatchStateControllerTest extends WebTestCase
{
    private const string ROUND = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testANeverStartedStopwatch(): void
    {
        $state = $this->state();

        self::assertSame([
            'roundId', 'status', 'startedAt', 'stoppedAt', 'minutesLimit', 'startedAtMs', 'stoppedAtMs', 'asOfMs', 'stateHash', 'serverNow', 'serverNowMs',
        ], array_keys($state));
        self::assertSame(self::ROUND, $state['roundId']);
        self::assertNull($state['status']);
        self::assertNull($state['startedAt']);
        self::assertNull($state['startedAtMs']);
        self::assertSame(60, $state['minutesLimit']);
        self::assertIsInt($state['serverNowMs']);
        self::assertIsInt($state['asOfMs']);
        self::assertLessThanOrEqual($state['serverNowMs'], $state['asOfMs'], 'the row is read before the answer is sent');
        self::assertIsString($state['serverNow']);
        self::assertSame(intdiv($state['serverNowMs'], 1000), (int) new DateTimeImmutable($state['serverNow'])->format('U'));
        self::assertTrue($this->browser->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function testAScheduledStartIsScheduledBeforeItsMoment(): void
    {
        $startsAt = $this->now()->modify('+10 minutes');
        $this->stopwatch('scheduled', $startsAt);

        $state = $this->state();

        self::assertSame('scheduled', $state['status']);
        self::assertSame($startsAt->getTimestamp() * 1000, $state['startedAtMs']);
        self::assertSame($startsAt->format(\DateTimeInterface::ATOM), $state['startedAt']);
        self::assertGreaterThan($state['serverNowMs'], $state['startedAtMs']);
    }

    public function testAScheduledStartRunsOnceItsMomentHasPassed(): void
    {
        $startedAt = $this->now()->modify('-5 seconds');
        $this->stopwatch('scheduled', $startedAt);

        $state = $this->state();

        self::assertSame('running', $state['status']);
        self::assertSame($startedAt->getTimestamp() * 1000, $state['startedAtMs']);
        // Nothing was written at that moment
        self::assertSame('scheduled', self::getContainer()->get(Connection::class)->fetchOne('SELECT stopwatch_status FROM competition_round WHERE id = :id', ['id' => self::ROUND]));
    }

    public function testAnUnknownRoundIsNotFound(): void
    {
        $this->browser->request('GET', '/round-stopwatch-state/not-a-uuid');
        self::assertResponseStatusCodeSame(404);

        $this->browser->request('GET', '/round-stopwatch-state/018d0005-0000-0000-0000-999999999999');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $this->browser->request('GET', '/round-stopwatch-state/' . self::ROUND);
        self::assertResponseIsSuccessful();

        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        /** @var array<string, mixed> $state */
        return $state;
    }

    private function stopwatch(string $status, DateTimeImmutable $startedAt): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET stopwatch_status = :status, stopwatch_started_at = :startedAt WHERE id = :id',
            ['status' => $status, 'startedAt' => $startedAt->format('Y-m-d H:i:s'), 'id' => self::ROUND],
        );
    }

    private function now(): DateTimeImmutable
    {
        $now = self::getContainer()->get(ClockInterface::class)->now();

        return $now->setTimestamp($now->getTimestamp());
    }
}
