<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineMediaCheck;
use SpeedPuzzling\Web\Message\RecordOnlineMediaCheck;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Value\OnlineCvAnswer;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The CV worker's answer as the photo's OnlineMediaCheck (RecordOnlineMediaCheck - Phase 3, docs/features/
 * online-competitions/README.md §5.8): written apart from the check (which waits for the worker outside any
 * transaction), under the photo's lock - two checks of one photo answering at once take turns, and the second answer of
 * a version that has its row is "already checked": nothing changes, nothing breaks the unique (media, version); a
 * forced re-check replaces it; a failure is a row of its own and never touches a verdict.
 */
final class RecordOnlineMediaCheckHandlerTest extends KernelTestCase
{
    use OnlineReviewSeeding;

    private const string VERSION = 'w1-c1';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testASecondAnswerOfTheSameVersionChangesNothing(): void
    {
        $photo = $this->photo();

        $this->record($photo, self::verdict(OnlineFinishCheckVerdict::Gaps));
        $this->record($photo, self::verdict(OnlineFinishCheckVerdict::Complete));

        self::assertSame([self::VERSION => 'gaps'], $this->verdicts($photo));
    }

    public function testAForcedAnswerReplacesTheRowOfItsVersion(): void
    {
        $photo = $this->photo();
        $this->record($photo, self::verdict(OnlineFinishCheckVerdict::Gaps));

        $this->record($photo, self::verdict(OnlineFinishCheckVerdict::Unsure), attempts: 2, force: true);
        $this->record($photo, self::verdict(OnlineFinishCheckVerdict::Complete, 'w2-c1'), force: true);

        self::assertSame([self::VERSION => 'unsure', 'w2-c1' => 'complete'], $this->verdicts($photo));
        self::assertSame(2, self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT attempts FROM online_media_check WHERE media_id = :id AND worker_version = :version',
            ['id' => $photo->id->toString(), 'version' => self::VERSION],
        ));
    }

    public function testAFailureIsARowOfItsOwnAndTheVerdictStays(): void
    {
        $photo = $this->photo();
        $this->record($photo, self::verdict(OnlineFinishCheckVerdict::Complete));

        $this->record($photo, OnlineCvAnswer::failed('download_failed:finish'), attempts: 4);
        $this->record($photo, OnlineCvAnswer::failed('finish_unreadable'), attempts: 1);

        self::assertSame([OnlineMediaCheck::NO_VERSION => 'failed', self::VERSION => 'complete'], $this->verdicts($photo));
        self::assertSame('finish_unreadable', self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT error FROM online_media_check WHERE media_id = :id AND worker_version = :version',
            ['id' => $photo->id->toString(), 'version' => OnlineMediaCheck::NO_VERSION],
        ));
    }

    public function testAPhotoDeletedMeanwhileHasNothingToRecord(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordOnlineMediaCheck('018d0050-0000-7000-8000-00000000dead', self::verdict(OnlineFinishCheckVerdict::Complete), 1));

        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM online_media_check'));
    }

    public function testTheAnswersOfOnePhotoTakeTurns(): void
    {
        $message = new RecordOnlineMediaCheck('018D0050-0000-7000-8000-0000000000AA', self::verdict(OnlineFinishCheckVerdict::Complete), 1);

        self::assertSame('online-media-check-018d0050-0000-7000-8000-0000000000aa', $message->lockKey());
        self::assertSame($message->lockKey(), (new RecordOnlineMediaCheck('018d0050-0000-7000-8000-0000000000aa', OnlineCvAnswer::failed('busy'), 4))->lockKey());
        self::assertNotSame($message->lockKey(), (new RecordOnlineMediaCheck('018d0050-0000-7000-8000-0000000000ab', OnlineCvAnswer::failed('busy'), 4))->lockKey());
    }

    private function photo(): OnlineEntryMedia
    {
        $startMs = $this->startOnlineRound();
        $entry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $photo = $this->evidence($entry, OnlineMediaKind::Button, $startMs + 600_000, $this->finishTap($entry, $startMs + 600_000));
        $photo->markVerified($photo->declaredBytes);
        $this->entityManager()->flush();

        return $photo;
    }

    private function record(OnlineEntryMedia $photo, OnlineCvAnswer $answer, int $attempts = 1, bool $force = false): void
    {
        // As CheckOnlineFinishPhotoHandler sends it: through the bus - the photo's lock, a transaction of its own
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordOnlineMediaCheck($photo->id->toString(), $answer, $attempts, $force));
    }

    private static function verdict(OnlineFinishCheckVerdict $verdict, string $version = self::VERSION): OnlineCvAnswer
    {
        return OnlineCvAnswer::verdict(
            verdict: $verdict,
            confidence: 0.8,
            gaps: [],
            reason: 'checked',
            method: 'holes',
            version: $version,
            ms: 200,
            imageWidth: 1920,
            imageHeight: 1080,
            details: null,
        );
    }

    /**
     * @return array<string, string> worker version => verdict, by version
     */
    private function verdicts(OnlineEntryMedia $photo): array
    {
        /** @var array<string, string> $verdicts */
        $verdicts = self::getContainer()->get(Connection::class)->fetchAllKeyValue(
            'SELECT worker_version, verdict FROM online_media_check WHERE media_id = :id',
            ['id' => $photo->id->toString()],
        );
        ksort($verdicts, SORT_STRING);

        return $verdicts;
    }
}
