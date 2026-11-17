<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\WarmAnalyticsStudioPredictions;
use SpeedPuzzling\Web\MessageHandler\WarmAnalyticsStudioPredictionsHandler;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioContextLoader;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioPredictions;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioRoundPredictionsBuilder;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Value\ShownPredictionModel;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The round-wide prediction warm of an analytics studio round (analytics-studio.md R28-R33) on Group B of the
 * "Results Cup": Gina (private), Hugo (PLAYER_REGULAR, public) and Ivan (unlinked). It writes exactly what the entrant
 * answer would (the same key, the same numbers), skips everybody who gets none, does nothing for a pair round, another
 * visible set or a stale message, ends a turn by queueing the next one, and never computes anything for a hidden puzzle.
 */
final class WarmAnalyticsStudioPredictionsHandlerTest extends KernelTestCase
{
    use AnalyticsStudioTesting;

    private const string HUGO = PlayerFixture::PLAYER_REGULAR;
    private const string HIDDEN_SLOT = '018d0020-0000-0000-0000-00000000aaa2';

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->logger = new InMemoryLogger();
        $this->clearAnalyticsStudioCache();
    }

    public function testTheWarmWritesWhatTheEntrantAnswerShows(): void
    {
        // Group B on a puzzle Hugo has solved three times: a personal prediction
        $this->studioDatabase()->executeStatement('UPDATE competition_round_puzzle SET puzzle_id = :puzzle WHERE round_id = :round', ['puzzle' => PuzzleFixture::PUZZLE_500_02, 'round' => F::ROUND_GROUP_B]);
        $expected = self::getContainer()->get(GetPlayerPredictions::class)->forPuzzles(self::HUGO, [PuzzleFixture::PUZZLE_500_02])[PuzzleFixture::PUZZLE_500_02] ?? null;
        self::assertNotNull($expected, 'no canary: no prediction for the fixture player');
        self::getContainer()->get(GetPlayerPredictions::class)->reset();

        $this->handler()($this->message(F::ROUND_GROUP_B));

        $cached = $this->cached(self::HUGO, [PuzzleFixture::PUZZLE_500_02]);
        self::assertIsArray($cached);
        self::assertSame(
            ['seconds' => $expected->predictedSeconds, 'low50' => $expected->rangeLowSeconds, 'high50' => $expected->rangeHighSeconds, 'method' => 'personal'],
            array_intersect_key(self::digArray($cached, PuzzleFixture::PUZZLE_500_02), ['seconds' => 0, 'low50' => 0, 'high50' => 0, 'method' => 0]),
        );
        self::assertSame([], $this->sent(), 'one turn did it all');
        self::assertSame([], $this->logger->records);
    }

    public function testStatementsAreTheContextAndTheEntrantsPlusThePredictionsOfWhoIsNotCached(): void
    {
        // Cold: the round, its visible puzzles, the entrants (3) + Hugo's predictions (4, like `predictions` of one entry)
        self::assertSame(7, $this->statementsOf($this->message(F::ROUND_GROUP_B)));
        // Everybody cached: the multi-get finds them - only the three
        self::assertSame(3, $this->statementsOf($this->message(F::ROUND_GROUP_B)));
    }

    public function testNobodyWithoutPublicHistoryOrWhoOptedOutIsComputed(): void
    {
        $this->studioDatabase()->executeStatement('UPDATE player SET time_predictions_opted_out = true WHERE id = :id', ['id' => self::HUGO]);

        // Gina private, Hugo opted out, Ivan unlinked: nothing beyond the three
        self::assertSame(3, $this->statementsOf($this->message(F::ROUND_GROUP_B)));
        self::assertFalse($this->isCached(self::HUGO, [$this->puzzleOf(F::ROUND_GROUP_B)]));
        self::assertFalse($this->isCached(PlayerFixture::PLAYER_PRIVATE, [$this->puzzleOf(F::ROUND_GROUP_B)]));
    }

    public function testAPairRoundGetsNothing(): void
    {
        // The round and its puzzles only - no entrants, no prediction (D12)
        self::assertSame(2, $this->statementsOf($this->message(F::ROUND_PAIRS)));
        self::assertSame([], $this->sent());
    }

    public function testAnotherVisibleSetOrAStaleMessageDoesNothing(): void
    {
        $handler = $this->handler();
        $message = $this->message(F::ROUND_GROUP_B);

        self::assertSame(2, $this->statementsOf(new WarmAnalyticsStudioPredictions(F::ROUND_GROUP_B, 'another-hash', $message->requestedAtMs)));
        self::assertSame(0, $this->statementsOf(new WarmAnalyticsStudioPredictions(F::ROUND_GROUP_B, $message->visibleHash, TestClock::milliseconds() - WarmAnalyticsStudioPredictionsHandler::STALE_AFTER_MS - 1)));
        self::assertFalse($this->isCached(self::HUGO, [$this->puzzleOf(F::ROUND_GROUP_B)]));

        // A message just inside the window still works
        $handler(new WarmAnalyticsStudioPredictions(F::ROUND_GROUP_B, $message->visibleHash, TestClock::milliseconds() - WarmAnalyticsStudioPredictionsHandler::STALE_AFTER_MS + 1000));
        self::assertTrue($this->isCached(self::HUGO, [$this->puzzleOf(F::ROUND_GROUP_B)]));
    }

    public function testAnUnknownRoundIsLoggedQuietly(): void
    {
        $this->handler()(new WarmAnalyticsStudioPredictions('018d0020-0000-0000-0000-00000000ffff', 'x', TestClock::milliseconds()));

        self::assertTrue($this->logger->hasRecord('info', 'not there'));
        self::assertFalse($this->logger->hasRecord('warning', ''));
    }

    public function testATurnEndsByQueueingTheNextOne(): void
    {
        // Ivan linked to a second public player: two to compute, one per turn
        $this->studioDatabase()->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', ['player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'id' => F::PARTICIPANT_IVAN]);
        $puzzle = $this->puzzleOf(F::ROUND_GROUP_B);
        $players = [self::HUGO, PlayerFixture::PLAYER_WITH_FAVORITES];
        sort($players);
        $message = $this->message(F::ROUND_GROUP_B);

        $this->handler(membersPerTurn: 1)($message);

        self::assertTrue($this->isCached($players[0], [$puzzle]), 'by player id');
        self::assertFalse($this->isCached($players[1], [$puzzle]));
        $sent = $this->sent();
        self::assertCount(1, $sent);
        $next = $sent[0]->getMessage();
        self::assertInstanceOf(WarmAnalyticsStudioPredictions::class, $next);
        self::assertSame([F::ROUND_GROUP_B, $message->visibleHash, $message->requestedAtMs, 1], [$next->roundId, $next->visibleHash, $next->requestedAtMs, $next->turn]);
        // The chain renewed the round's warming marker: an answer meanwhile starts no second chain
        self::assertNull($this->builder()->build(F::ROUND_GROUP_B)->warm);

        // The next turn finds the first cached and finishes
        $this->transport()->reset();
        $this->handler(membersPerTurn: 1)($next);
        self::assertTrue($this->isCached($players[1], [$puzzle]));
        self::assertSame([], $this->sent());
        self::assertSame('ready', self::dig($this->builder()->build(F::ROUND_GROUP_B)->json, 'status'));

        // The last turn allowed stops with a warning instead of queueing another
        $this->clearAnalyticsStudioCache();
        $this->handler(membersPerTurn: 1)(new WarmAnalyticsStudioPredictions(F::ROUND_GROUP_B, $message->visibleHash, $message->requestedAtMs, WarmAnalyticsStudioPredictionsHandler::MAX_TURNS));
        self::assertSame([], $this->sent());
        self::assertTrue($this->logger->hasRecord('warning', 'stopped after its last turn'));
        // The ended chain took its marker away: the next answer that misses somebody sends a new warm at once
        $this->clearAnalyticsStudioCache();
        $this->builder()->markWarming($message);
        $this->handler(membersPerTurn: 1)(new WarmAnalyticsStudioPredictions(F::ROUND_GROUP_B, $message->visibleHash, $message->requestedAtMs, WarmAnalyticsStudioPredictionsHandler::MAX_TURNS));
        self::assertNotNull($this->builder()->build(F::ROUND_GROUP_B)->warm);
    }

    public function testOneMemberFailingDoesNotStopTheOthers(): void
    {
        // Ivan linked to a second public player; Hugo's computation fails
        $this->studioDatabase()->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', ['player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'id' => F::PARTICIPANT_IVAN]);
        $puzzle = $this->puzzleOf(F::ROUND_GROUP_B);
        $container = self::getContainer();
        $realCache = $container->get('analytics_studio_cache');
        self::assertInstanceOf(CacheInterface::class, $realCache);
        $failingForHugo = new class ($realCache, strtolower(self::HUGO)) implements CacheInterface {
            public function __construct(
                private readonly CacheInterface $cache,
                private readonly string $failingPlayerId,
            ) {
            }

            /**
             * @param callable(\Symfony\Contracts\Cache\ItemInterface, bool): mixed $callback
             * @param null|array<mixed> $metadata
             */
            public function get(string $key, callable $callback, null|float $beta = null, null|array &$metadata = null): mixed
            {
                if (str_contains($key, $this->failingPlayerId)) {
                    throw new \RuntimeException('The prediction of this player fails');
                }

                return $this->cache->get($key, $callback, $beta, $metadata);
            }

            public function delete(string $key): bool
            {
                return $this->cache->delete($key);
            }
        };
        $predictions = new AnalyticsStudioPredictions($container->get(GetPlayerPredictions::class), $failingForHugo, $this->pool(), $this->shownModel());
        $message = $this->message(F::ROUND_GROUP_B);

        $this->handler(predictions: $predictions)($message);

        self::assertCount(1, $this->logger->records, 'one warning for the one player');
        self::assertTrue($this->logger->hasRecord('warning', 'Could not compute a time prediction'));
        $context = $this->logger->records[0]['context'];
        self::assertInstanceOf(\RuntimeException::class, $context['exception'] ?? null);
        self::assertTrue($this->isCached(PlayerFixture::PLAYER_WITH_FAVORITES, [$puzzle]), 'the others are computed');
        self::assertSame([], $this->cached(self::HUGO, [$puzzle]), '"no prediction" is kept for the failing player - not tried again');

        // The next warm finds everybody cached: nothing computed, nothing logged
        $this->logger->records = [];
        $this->handler(predictions: $predictions)($message);
        self::assertSame([], $this->logger->records);
        self::assertSame('ready', self::dig($this->builder()->build(F::ROUND_GROUP_B)->json, 'status'));
    }

    public function testAHiddenPuzzleIsNeverInAnyKeyAndNothingIsWarmedBeforeAReveal(): void
    {
        $database = $this->studioDatabase();
        $visible = $this->puzzleOf(F::ROUND_GROUP_B);
        $database->executeStatement(
            "INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts, hide_mode, reveal_mode, reveal_at, hides_everywhere, position)
             VALUES (:id, :round, :puzzle, true, 'entirely', 'manual', NULL, false, 2)",
            ['id' => self::HIDDEN_SLOT, 'round' => F::ROUND_GROUP_B, 'puzzle' => PuzzleFixture::PUZZLE_1000_02],
        );

        $this->handler()($this->message(F::ROUND_GROUP_B));

        self::assertTrue($this->isCached(self::HUGO, [$visible]));
        self::assertFalse($this->isCached(self::HUGO, [$visible, PuzzleFixture::PUZZLE_1000_02]));
        self::assertFalse($this->isCached(self::HUGO, [PuzzleFixture::PUZZLE_1000_02]));

        // Every slot hidden: nothing is visible, nothing is computed - the round and its puzzles only
        $database->executeStatement("UPDATE competition_round_puzzle SET hide_until_round_starts = true, hide_mode = 'entirely', reveal_mode = 'manual', reveal_at = NULL WHERE round_id = :round", ['round' => F::ROUND_GROUP_B]);
        $this->clearAnalyticsStudioCache();
        $message = $this->message(F::ROUND_GROUP_B);
        self::assertSame(2, $this->statementsOf($message));
        self::assertFalse($this->isCached(self::HUGO, [$visible]));
    }

    public function testTheWarmGoesToTheImportsQueueAndTheEmailsStayOnAsync(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $bus->dispatch($this->message(F::ROUND_GROUP_B));
        $bus->dispatch(new SendEmailMessage((new Email())->from('a@example.com')->to('b@example.com')->subject('x')->text('x')));

        self::assertCount(1, $this->sent(), 'queued on imports, not handled at once');
        self::assertFalse($this->isCached(self::HUGO, [$this->puzzleOf(F::ROUND_GROUP_B)]));
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        self::assertSame([SendEmailMessage::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, array_values([...$async->getSent()])));
    }

    private function handler(int $membersPerTurn = PHP_INT_MAX, null|AnalyticsStudioPredictions $predictions = null): WarmAnalyticsStudioPredictionsHandler
    {
        $container = self::getContainer();

        return new WarmAnalyticsStudioPredictionsHandler(
            $container->get(AnalyticsStudioContextLoader::class),
            $container->get(GetAnalyticsStudioEntrants::class),
            $predictions ?? $container->get(AnalyticsStudioPredictions::class),
            $this->builder(),
            $container->get(GetPlayerPredictions::class),
            $container->get(MessageBusInterface::class),
            $container->get(ClockInterface::class),
            $this->logger,
            membersPerTurn: $membersPerTurn,
        );
    }

    private function builder(): AnalyticsStudioRoundPredictionsBuilder
    {
        return self::getContainer()->get(AnalyticsStudioRoundPredictionsBuilder::class);
    }

    /**
     * A message as the round-predictions answer sends it: the round's visible hash now, requested now.
     */
    private function message(string $roundId): WarmAnalyticsStudioPredictions
    {
        $context = self::getContainer()->get(AnalyticsStudioContextLoader::class)->load($roundId);

        return new WarmAnalyticsStudioPredictions($roundId, $context->visibleHash, TestClock::milliseconds());
    }

    private function statementsOf(WarmAnalyticsStudioPredictions $message): int
    {
        $container = self::getContainer();
        // Like a fresh consumer process: no memo of an earlier message
        $container->get(GetPlayerPredictions::class)->reset();
        $container->get(AnalyticsStudioPredictions::class)->reset();

        /** @var DebugDataHolder $debugData */
        $debugData = $container->get('doctrine.debug_data_holder');
        $debugData->reset();

        $this->handler()($message);

        $count = 0;
        foreach ($debugData->getData() as $queries) {
            $count += is_array($queries) ? count($queries) : 0;
        }

        return $count;
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function cached(string $playerId, array $puzzleIds): mixed
    {
        $item = $this->pool()->getItem(AnalyticsStudioPredictions::cacheKey(strtolower($playerId), $puzzleIds, ShownPredictionModel::fromSetting($this->shownModel())));

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function isCached(string $playerId, array $puzzleIds): bool
    {
        return $this->cached($playerId, $puzzleIds) !== null;
    }

    private function shownModel(): string
    {
        $model = self::getContainer()->getParameter('predictionShownModel');
        self::assertIsString($model);

        return $model;
    }

    private function pool(): CacheItemPoolInterface
    {
        $pool = self::getContainer()->get('analytics_studio_cache');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    private function puzzleOf(string $roundId): string
    {
        return strtolower($this->studioString('SELECT puzzle_id FROM competition_round_puzzle WHERE round_id = :round ORDER BY position NULLS LAST, id LIMIT 1', ['round' => $roundId]));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @return list<Envelope>
     */
    private function sent(): array
    {
        return array_values(array_filter(
            [...$this->transport()->getSent()],
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof WarmAnalyticsStudioPredictions,
        ));
    }
}
