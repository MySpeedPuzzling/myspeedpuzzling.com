<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineMediaCheck;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\CheckOnlineFinishPhoto;
use SpeedPuzzling\Web\MessageHandler\CheckOnlineFinishPhotoHandler;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Repository\OnlineMediaCheckRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvClient;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvInputs;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvSettings;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OnlineCvTesting;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\OnlineCvHttpMock;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The completeness check of one finish photo (CheckOnlineFinishPhotoHandler, docs/features/online-competitions/
 * README.md §5.8 - Phase 3): what the CV worker gets (presigned URLs for the stores' server-side endpoint), the verdict
 * stored per worker version, a busy worker asked again later by a message of its own, a refusal recorded quietly - and
 * nothing at all while the check is off. The worker answers from OnlineCvHttpMock.
 */
final class CheckOnlineFinishPhotoHandlerTest extends KernelTestCase
{
    use OnlineCvTesting;
    use OnlineReviewSeeding;

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->turnCompletenessCheckOn();
        self::bootKernel();
        $this->logger = new InMemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->restoreCompletenessCheck();

        parent::tearDown();
    }

    public function testTheWorkerGetsThePhotoTheEmptyMatTheReferenceAndThePieces(): void
    {
        $puzzle = $this->entityManager()->find(Puzzle::class, PuzzleFixture::PUZZLE_3000);
        self::assertInstanceOf(Puzzle::class, $puzzle);
        $puzzle->image = 'trefl/puzzle-15-3000.jpg';
        $photo = $this->finishPhoto();

        $this->check($photo);

        $requests = OnlineCvHttpMock::requests();
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame(self::ONLINE_CV_TEST_URL . '/check', $requests[0]['url']);

        $body = OnlineCvHttpMock::body(0);
        // Made for S3_ENDPOINT - where the worker reaches the store inside the network, never the browser's endpoint
        self::assertIsString($body['finishUrl']);
        self::assertStringStartsWith('http://minio:9000/puzzle/' . $photo->objectKey . '?', $body['finishUrl']);
        self::assertStringContainsString('X-Amz-Expires=' . OnlineCvInputs::URL_SECONDS, $body['finishUrl']);
        self::assertStringContainsString('X-Amz-Signature=', $body['finishUrl']);
        self::assertIsString($body['emptyMatUrl']);
        self::assertStringContainsString(OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED . '.jpg?', $body['emptyMatUrl']);
        self::assertIsString($body['referenceUrl']);
        self::assertStringStartsWith('http://minio:9000/puzzle/trefl/puzzle-15-3000.jpg?', $body['referenceUrl']);
        self::assertSame(3000, $body['pieceCount']);
    }

    public function testTheVerdictIsStoredForTheWorkersVersion(): void
    {
        $photo = $this->finishPhoto();
        OnlineCvHttpMock::queue(OnlineCvHttpMock::verdict('gaps', [['x' => 812, 'y' => 403, 'w' => 41, 'h' => 38]], 0.74));

        $this->check($photo);

        $row = $this->rows($photo)[0] ?? null;
        self::assertNotNull($row);
        self::assertSame(OnlineCvHttpMock::VERSION, $row['worker_version']);
        self::assertSame('gaps', $row['verdict']);
        self::assertEqualsWithDelta(0.74, $row['confidence'], 0.0001);
        self::assertSame([['h' => 38, 'w' => 41, 'x' => 812, 'y' => 403]], self::sortedBoxes($row['gaps']));
        self::assertSame('holes', $row['method']);
        self::assertSame(143, $row['ms']);
        self::assertSame(1920, $row['image_width']);
        self::assertSame(1080, $row['image_height']);
        self::assertSame(1, $row['attempts']);
        self::assertNull($row['error']);
        self::assertTrue($this->logger->hasRecord('info', 'checked for completeness'));
    }

    public function testNothingIsSentWhileTheCheckIsOff(): void
    {
        $photo = $this->finishPhoto();
        $handler = $this->handler(new OnlineCvSettings(''));

        $handler(new CheckOnlineFinishPhoto($photo->id->toString()));
        $this->entityManager()->flush();

        self::assertSame([], OnlineCvHttpMock::requests());
        self::assertSame([], $this->rows($photo));
    }

    public function testOnlyAVerifiedFinishPhotoIsChecked(): void
    {
        $entry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $unverified = $this->evidence($entry, OnlineMediaKind::Button, $this->startOnlineRound() + 60_000);
        $periodic = $this->evidence($entry, OnlineMediaKind::Periodic, $this->startOnlineRound() + 90_000);
        $periodic->markVerified(35_000);
        $this->entityManager()->flush();

        $this->check($unverified);
        $this->check($periodic);
        $this->check(OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED);
        $this->check('018d0050-0000-7000-8000-00000000dead');

        self::assertSame([], OnlineCvHttpMock::requests());
    }

    public function testAPhotoWithAVerdictIsCheckedAgainOnlyWhenForced(): void
    {
        $photo = $this->finishPhoto();
        $this->check($photo);
        $this->check($photo);
        self::assertCount(1, OnlineCvHttpMock::requests(), 'one verdict is enough');

        // Forced, the same version: its row is replaced
        OnlineCvHttpMock::queue(OnlineCvHttpMock::verdict('unsure', confidence: 0.3));
        $this->check($photo, force: true);
        $rows = $this->rows($photo);
        self::assertCount(1, $rows);
        self::assertSame('unsure', $rows[0]['verdict']);

        // Forced, a newer worker: a row of its own next to the older one
        OnlineCvHttpMock::queue(OnlineCvHttpMock::verdict('complete', version: 'w2-c1'));
        $this->check($photo, force: true);
        $rows = $this->rows($photo);
        self::assertSame([OnlineCvHttpMock::VERSION => 'unsure', 'w2-c1' => 'complete'], array_column($rows, 'verdict', 'worker_version'));
    }

    public function testABusyWorkerIsAskedAgainLaterByAMessageOfItsOwn(): void
    {
        $photo = $this->finishPhoto();
        OnlineCvHttpMock::queue(OnlineCvHttpMock::error(503, 'busy'));

        $this->check($photo);

        self::assertSame([], $this->rows($photo), 'nothing recorded yet');
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(CheckOnlineFinishPhoto::class, $message);
        self::assertSame([$photo->id->toString(), 2, false], [$message->mediaId, $message->attempt, $message->force]);
        self::assertSame(CheckOnlineFinishPhotoHandler::RETRY_DELAYS_MS[0], $sent[0]->last(DelayStamp::class)?->getDelay());
        self::assertFalse($this->logger->hasRecord('warning', 'completeness'), 'a retry is no warning');
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function tryAgainLater(): iterable
    {
        yield 'a store the worker could not reach' => [OnlineCvHttpMock::error(502, 'download_failed', ['input' => 'finish', 'status' => 503])];
        yield 'busy' => [OnlineCvHttpMock::error(503, 'busy')];
        yield 'no answer in time' => [OnlineCvHttpMock::error(504, 'gateway_timeout')];
    }

    #[DataProvider('tryAgainLater')]
    public function testAfterTheLastAttemptTheCheckIsRecordedAsFailed(MockResponse $answer): void
    {
        $photo = $this->finishPhoto();
        OnlineCvHttpMock::queue($answer);

        $this->check($photo, attempt: CheckOnlineFinishPhotoHandler::MAX_ATTEMPTS);

        self::assertSame([], $this->transport()->getSent(), 'no more tries');
        $rows = $this->rows($photo);
        self::assertCount(1, $rows);
        self::assertSame([OnlineMediaCheck::NO_VERSION, 'failed', CheckOnlineFinishPhotoHandler::MAX_ATTEMPTS], [$rows[0]['worker_version'], $rows[0]['verdict'], $rows[0]['attempts']]);
        self::assertIsString($rows[0]['error']);
        self::assertNotSame('', $rows[0]['error']);
    }

    public function testARefusedPhotoIsRecordedAtOnceAndTheRoundWarnedOnce(): void
    {
        $first = $this->finishPhoto();
        $second = $this->finishPhoto(120_000);
        OnlineCvHttpMock::queue(
            OnlineCvHttpMock::error(422, 'finish_unavailable', ['status' => 404]),
            OnlineCvHttpMock::error(422, 'finish_unreadable'),
        );

        $this->check($first);
        $this->check($second);

        self::assertSame([], $this->transport()->getSent(), 'a refusal is never asked again');
        self::assertSame('finish_unavailable', $this->rows($first)[0]['error'] ?? null);
        self::assertSame('finish_unreadable', $this->rows($second)[0]['error'] ?? null);
        // A person looks at the first failure of a round; the rest are the same story - no Sentry flood
        self::assertCount(1, array_filter($this->logger->records, static fn (array $record): bool => $record['level'] === 'warning'));
        self::assertTrue($this->logger->hasRecord('info', 'could not run'));
    }

    public function testAVerdictAfterAFailureAddsItsRowAndTheFailureStays(): void
    {
        $photo = $this->finishPhoto();
        OnlineCvHttpMock::queue(OnlineCvHttpMock::error(500, 'check_failed'));
        $this->check($photo);

        OnlineCvHttpMock::queue(OnlineCvHttpMock::verdict('complete'));
        $this->check($photo);

        self::assertSame([OnlineMediaCheck::NO_VERSION => 'failed', OnlineCvHttpMock::VERSION => 'complete'], array_column($this->rows($photo), 'verdict', 'worker_version'));
        self::assertTrue(self::getContainer()->get(OnlineMediaCheckRepository::class)->hasVerdict($photo->id->toString()));
    }

    /**
     * A verified Finished tap's photo of Olivia, $afterStartMs into the round
     */
    private function finishPhoto(int $afterStartMs = 600_000): OnlineEntryMedia
    {
        $startMs = $this->startOnlineRound();
        $entry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapId = $this->finishTap($entry, $startMs + $afterStartMs);
        $photo = $this->evidence($entry, OnlineMediaKind::Button, $startMs + $afterStartMs + 300, $tapId);
        $photo->markVerified($photo->declaredBytes);
        $this->entityManager()->flush();

        return $photo;
    }

    private function check(OnlineEntryMedia|string $photo, int $attempt = 1, bool $force = false): void
    {
        ($this->handler())(new CheckOnlineFinishPhoto($photo instanceof OnlineEntryMedia ? $photo->id->toString() : $photo, $attempt, $force));
        $this->entityManager()->flush();
    }

    private function handler(null|OnlineCvSettings $settings = null): CheckOnlineFinishPhotoHandler
    {
        $container = self::getContainer();

        return new CheckOnlineFinishPhotoHandler(
            $settings ?? $container->get(OnlineCvSettings::class),
            $container->get(OnlineEntryMediaRepository::class),
            $container->get(OnlineMediaCheckRepository::class),
            $container->get(OnlineCvInputs::class),
            $container->get(OnlineCvClient::class),
            $container->get(MessageBusInterface::class),
            $container->get(ClockInterface::class),
            $this->logger,
        );
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @return list<array{worker_version: string, verdict: string, confidence: null|float, gaps: string, method: null|string, ms: null|int, image_width: null|int, image_height: null|int, attempts: int, error: null|string}>
     */
    private function rows(OnlineEntryMedia $photo): array
    {
        /** @var list<array{worker_version: string, verdict: string, confidence: null|float, gaps: string, method: null|string, ms: null|int, image_width: null|int, image_height: null|int, attempts: int, error: null|string}> $rows */
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT worker_version, verdict, confidence, gaps, method, ms, image_width, image_height, attempts, error FROM online_media_check WHERE media_id = :id ORDER BY checked_at, worker_version',
            ['id' => $photo->id->toString()],
        );

        return $rows;
    }

    /**
     * @return list<array<string, int>>
     */
    private static function sortedBoxes(string $json): array
    {
        $boxes = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($boxes);

        return array_map(static function (mixed $box): array {
            self::assertIsArray($box);
            ksort($box);

            /** @var array<string, int> $box */
            return $box;
        }, array_values($boxes));
    }
}
