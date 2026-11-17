<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioRound;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioSignals;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioWatch;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\Update;

/**
 * The analytics studio's public data-free signal (analytics-studio.md R21-R24, AnalyticsStudioSignals): a round whose
 * studio was opened (a state computed - AnalyticsStudioWatch) hears of its writes on `/analytics-studio-signal/{roundId}`
 * - public, `{type, roundId}` only; results only while they are public; never for a draft; nothing for a round nobody
 * watches, a refused write or a hub that is down (the write answers as always).
 *
 * The marker pool is per kernel in tests (config/packages/test/cache.php): every test watching a round keeps its kernel
 * (disableReboot()) between the state and the write.
 */
final class AnalyticsStudioSignalsTest extends WebTestCase
{
    use AnalyticsStudioTesting;

    private const string CHANGES = '/en/official-results/rounds/%s/changes';

    public function testARoundNobodyWatchesPublishesOnlyWhatItAlwaysDid(): void
    {
        $browser = $this->organiser();

        $this->postJson($browser, sprintf(self::CHANGES, F::ROUND_GROUP_A), ['changes' => [self::table(F::ENTRY_A_FILIP, null, 6)]]);

        self::assertResponseIsSuccessful();
        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(1, $updates, 'the organisers\' private update only');
        self::assertSame([], self::signals());
    }

    public function testAWatchedPublishedRoundSignalsAResultsWriteWithAConstantPublicPayload(): void
    {
        $browser = $this->organiser();
        $this->watch($browser, F::ROUND_GROUP_A);

        $this->postJson($browser, sprintf(self::CHANGES, F::ROUND_GROUP_A), ['changes' => [self::table(F::ENTRY_A_FILIP, null, 6)]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['{"type":"analytics_studio.signal","roundId":"' . F::ROUND_GROUP_A . '"}'], self::signals());
    }

    public function testUnpublishedResultsSignalOnlyWhileTheEventShowsLiveResults(): void
    {
        $browser = $this->organiser();
        $this->watch($browser, F::ROUND_GROUP_B);

        // Group B: not published, live results off - a signal would tell that a result was just recorded
        $this->postJson($browser, sprintf(self::CHANGES, F::ROUND_GROUP_B), ['changes' => [self::table(F::ENTRY_B_IVAN, 3, 9)]]);
        self::assertResponseIsSuccessful();
        self::assertSame([], self::signals());

        // Live results on (competitor pages + their live results): the state shows them, the signal may say so
        $this->studioDatabase()->executeStatement(
            'UPDATE competition SET competitor_pages = true, competitor_pages_live_results = true WHERE id = :id',
            ['id' => F::COMPETITION_RESULTS_CUP],
        );
        self::hub()->reset();
        $this->postJson($browser, sprintf(self::CHANGES, F::ROUND_GROUP_B), ['changes' => [self::table(F::ENTRY_B_IVAN, 9, 8)]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['{"type":"analytics_studio.signal","roundId":"' . F::ROUND_GROUP_B . '"}'], self::signals());
    }

    public function testPublishingAWatchedRoundSignals(): void
    {
        $browser = $this->organiser();
        $this->watch($browser, F::ROUND_GROUP_B);

        $this->postJson($browser, '/en/official-results/rounds/' . F::ROUND_GROUP_B . '/publish', []);

        self::assertResponseIsSuccessful();
        self::assertSame(['{"type":"analytics_studio.signal","roundId":"' . F::ROUND_GROUP_B . '"}'], self::signals());
    }

    public function testARefusedWriteSignalsNothing(): void
    {
        $browser = $this->organiser();
        $this->watch($browser, F::ROUND_GROUP_A);

        // Filip has no table: a change from table 99 conflicts, nothing is applied - nothing to tell
        $this->postJson($browser, sprintf(self::CHANGES, F::ROUND_GROUP_A), ['changes' => [self::table(F::ENTRY_A_FILIP, 99, 6)]]);

        self::assertResponseIsSuccessful();
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    public function testAHubThatIsDownNeverFailsTheWrite(): void
    {
        $browser = $this->organiser();
        $this->watch($browser, F::ROUND_GROUP_A);
        self::hub()->failWith(new \RuntimeException('Hub down'));

        $this->postJson($browser, sprintf(self::CHANGES, F::ROUND_GROUP_A), ['changes' => [self::table(F::ENTRY_A_FILIP, null, 6)]]);

        self::assertResponseIsSuccessful();
        self::hub()->failWith(null);
        self::assertSame([], self::hub()->getPublishedUpdates());
    }

    public function testTheRevealPushAndRoundUpdatesOfAWatchedRoundSignal(): void
    {
        self::bootKernel();
        $this->studioWatch()->markWatched(strtoupper(F::ROUND_GROUP_B));
        $liveUpdates = self::getContainer()->get(OfficialResultsLiveUpdates::class);

        // Reveal now / a tick start / a reveal setting / arrange (the reveal push), a round update
        $liveUpdates->puzzlesRevealed(F::ROUND_GROUP_B);
        $liveUpdates->roundChanged(F::ROUND_GROUP_B);
        // Not watched: nothing
        $liveUpdates->puzzlesRevealed(F::ROUND_GROUP_A);
        // Proposals are no studio data
        $liveUpdates->proposalsChanged(F::ROUND_GROUP_B, [Uuid::uuid7()->toString()]);

        $signal = '{"type":"analytics_studio.signal","roundId":"' . F::ROUND_GROUP_B . '"}';
        self::assertSame([$signal, $signal], self::signals());
    }

    public function testAWatchedRoundOfADraftEventNeverSignals(): void
    {
        self::bootKernel();
        $watch = $this->studioWatch();
        // A marker set while the event was public: the gate reads the round at publish time
        $watch->markWatched(F::ROUND_GROUP_A);
        self::assertTrue($watch->watched(F::ROUND_GROUP_A));
        $this->studioDatabase()->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);

        $liveUpdates = self::getContainer()->get(OfficialResultsLiveUpdates::class);
        $liveUpdates->refresh(F::ROUND_GROUP_A);
        $liveUpdates->roundChanged(F::ROUND_GROUP_A);
        $liveUpdates->puzzlesRevealed(F::ROUND_GROUP_A);

        self::assertSame([], self::signals());
    }

    public function testAFailureIsAWarningAndAnUnknownRoundIsNothing(): void
    {
        self::bootKernel();
        $hub = new NullMercureHub();
        $logger = new InMemoryLogger();
        $watch = new AnalyticsStudioWatch(new ArrayAdapter());
        $signals = new AnalyticsStudioSignals($hub, $watch, self::getContainer()->get(GetAnalyticsStudioRound::class), $logger);

        // Watched but unknown or malformed: the studio's own visibility rule says no - silently
        foreach (['018d0020-0000-0000-0000-00000000dead', 'not-a-uuid'] as $roundId) {
            $watch->markWatched($roundId);
            $signals->roundChanged($roundId);
        }
        self::assertSame([], $hub->getPublishedUpdates());
        self::assertSame([], $logger->records);

        $watch->markWatched(F::ROUND_GROUP_A);
        $hub->failWith(new \RuntimeException('Hub down'));
        $signals->resultsChanged(F::ROUND_GROUP_A);

        self::assertTrue($logger->hasRecord('warning', 'Could not publish an analytics studio signal'));
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);

        $hub->failWith(null);
        $signals->resultsChanged(F::ROUND_GROUP_A);
        $updates = $hub->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame([AnalyticsStudioSignals::topic(F::ROUND_GROUP_A)], $updates[0]->getTopics());
        self::assertSame('/analytics-studio-signal/' . F::ROUND_GROUP_A, AnalyticsStudioSignals::topic(strtoupper(F::ROUND_GROUP_A)));
        self::assertFalse($updates[0]->isPrivate());
    }

    private function organiser(): KernelBrowser
    {
        $browser = self::createClient();
        // The marker lives in this kernel's pool (per kernel in tests) - kept between the state and the write
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        return $browser;
    }

    /**
     * The round's studio opened: a computed state marks it watched.
     */
    private function watch(KernelBrowser $browser, string $roundId): void
    {
        $this->clearAnalyticsStudioCache();
        $this->studioJson($browser, '/analytics-studio/' . $roundId . '/state');
        self::assertTrue($this->studioWatch()->watched($roundId));
        self::hub()->reset();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(KernelBrowser $browser, string $url, array $body): void
    {
        $browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(string $entryId, null|int $from, int $to): array
    {
        return ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => 'participant_round:' . $entryId, 'field' => 'table_number', 'from' => $from, 'to' => $to];
    }

    /**
     * The studio signals published (raw data) - each public, on its round's signal topic only.
     *
     * @return list<string>
     */
    private static function signals(): array
    {
        $signals = array_values(array_filter(
            self::hub()->getPublishedUpdates(),
            static fn (Update $update): bool => array_filter($update->getTopics(), static fn (string $topic): bool => str_starts_with($topic, '/analytics-studio-signal/')) !== [],
        ));

        foreach ($signals as $signal) {
            self::assertCount(1, $signal->getTopics());
            self::assertFalse($signal->isPrivate());
            /** @var array{roundId: string} $payload */
            $payload = json_decode($signal->getData(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([AnalyticsStudioSignals::topic($payload['roundId'])], $signal->getTopics());
        }

        return array_map(static fn (Update $update): string => $update->getData(), $signals);
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
