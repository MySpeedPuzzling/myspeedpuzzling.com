<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineMediaCheck;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\CheckOnlineFinishPhoto;
use SpeedPuzzling\Web\Message\CheckOnlineRoundFinishPhotos;
use SpeedPuzzling\Web\MessageHandler\CheckOnlineRoundFinishPhotosHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\OnlineCvTesting;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A round's finish photos to the completeness check (CheckOnlineRoundFinishPhotosHandler - what
 * `myspeedpuzzling:online-cv:check-round` dispatches, Phase 3): the verified `candidate` / `button` / `end` photos
 * without a verdict, every one when forced; nothing while the check is off.
 */
final class CheckOnlineRoundFinishPhotosHandlerTest extends KernelTestCase
{
    use OnlineCvTesting;
    use OnlineReviewSeeding;

    protected function tearDown(): void
    {
        $this->restoreCompletenessCheck();

        parent::tearDown();
    }

    public function testThePhotosWithoutAVerdictAreSentEveryOneWhenForced(): void
    {
        $this->turnCompletenessCheckOn();
        self::bootKernel();
        [$checked, $failed, $fresh] = $this->photos();

        self::assertSame(2, $this->handle(new CheckOnlineRoundFinishPhotos(OnlineRoundsFixture::ROUND_ONLINE_SOLO)));
        self::assertSame([[$failed, false], [$fresh, false]], $this->sent());

        $this->transport()->reset();
        self::assertSame(3, $this->handle(new CheckOnlineRoundFinishPhotos(OnlineRoundsFixture::ROUND_ONLINE_SOLO, force: true)));
        self::assertSame([[$checked, true], [$failed, true], [$fresh, true]], $this->sent());
    }

    public function testNothingIsSentWhileTheCheckIsOff(): void
    {
        self::bootKernel();
        $this->photos();

        self::assertSame(0, $this->handle(new CheckOnlineRoundFinishPhotos(OnlineRoundsFixture::ROUND_ONLINE_SOLO, force: true)));
        self::assertSame([], $this->sent());
    }

    public function testAnUnknownRoundIsNotFound(): void
    {
        $this->turnCompletenessCheckOn();
        self::bootKernel();

        $this->expectException(CompetitionRoundNotFound::class);
        $this->handle(new CheckOnlineRoundFinishPhotos(Uuid::uuid7()->toString()));
    }

    /**
     * Olivia's finish photos: one checked, one whose check failed, one not checked yet - plus evidence that is never
     * checked (a periodic photo, an unverified tap photo)
     *
     * @return array{string, string, string}
     */
    private function photos(): array
    {
        $startMs = $this->startOnlineRound();
        $entry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $photos = [];

        foreach ([OnlineMediaKind::Candidate, OnlineMediaKind::Button, OnlineMediaKind::End] as $index => $kind) {
            $photo = $this->evidence($entry, $kind, $startMs + 60_000 * ($index + 1));
            $photo->markVerified($photo->declaredBytes);
            $photos[] = $photo;
        }

        $this->evidence($entry, OnlineMediaKind::Periodic, $startMs + 30_000)->markVerified(35_000);
        $this->evidence($entry, OnlineMediaKind::Button, $startMs + 500_000);
        $this->checkRow($photos[0], OnlineFinishCheckVerdict::Complete, 'w1-c1');
        $this->checkRow($photos[1], OnlineFinishCheckVerdict::Failed, OnlineMediaCheck::NO_VERSION);
        $this->entityManager()->flush();

        return [$photos[0]->id->toString(), $photos[1]->id->toString(), $photos[2]->id->toString()];
    }

    private function checkRow(OnlineEntryMedia $photo, OnlineFinishCheckVerdict $verdict, string $version): void
    {
        $this->entityManager()->persist(new OnlineMediaCheck(Uuid::uuid7(), $photo, $version, $verdict, TestClock::now()));
    }

    private function handle(CheckOnlineRoundFinishPhotos $message): int
    {
        return self::getContainer()->get(CheckOnlineRoundFinishPhotosHandler::class)($message);
    }

    /**
     * @return list<array{string, bool}>
     */
    private function sent(): array
    {
        return array_values(array_map(static function ($envelope): array {
            $message = $envelope->getMessage();
            self::assertInstanceOf(CheckOnlineFinishPhoto::class, $message);

            return [$message->mediaId, $message->force];
        }, $this->transport()->getSent()));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
