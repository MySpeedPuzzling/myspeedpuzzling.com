<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Message\CheckOnlineFinishPhoto;
use SpeedPuzzling\Web\Message\VerifyOnlineMedia;
use SpeedPuzzling\Web\MessageHandler\VerifyOnlineMediaHandler;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\OnlineCvTesting;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A finish photo verified in its store goes to the completeness check (VerifyOnlineMediaHandler → CheckOnlineFinishPhoto,
 * Phase 3) - async, only a `candidate` / `button` / `end` photo, only while the check is on.
 */
final class VerifyOnlineMediaCompletenessCheckTest extends KernelTestCase
{
    use OnlineCvTesting;
    use OnlineReviewSeeding;

    protected function tearDown(): void
    {
        $this->restoreCompletenessCheck();

        parent::tearDown();
    }

    public function testAVerifiedFinishPhotoGoesToTheCheck(): void
    {
        $this->turnCompletenessCheckOn();
        self::bootKernel();

        $sent = [];

        foreach ([OnlineMediaKind::Candidate, OnlineMediaKind::Button, OnlineMediaKind::End] as $kind) {
            $photo = $this->verify($kind);
            $sent[] = $photo->id->toString();
        }

        $messages = array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent());
        self::assertContainsOnlyInstancesOf(CheckOnlineFinishPhoto::class, $messages);
        self::assertSame($sent, array_map(static fn (CheckOnlineFinishPhoto $message): string => $message->mediaId, $messages));
    }

    public function testOtherEvidenceIsNotChecked(): void
    {
        $this->turnCompletenessCheckOn();
        self::bootKernel();

        foreach ([OnlineMediaKind::Periodic, OnlineMediaKind::EmptyMat, OnlineMediaKind::Start, OnlineMediaKind::Burst] as $kind) {
            $this->verify($kind);
        }

        self::assertSame([], $this->transport()->getSent());
    }

    public function testNothingGoesAnywhereWhileTheCheckIsOff(): void
    {
        self::bootKernel();

        $photo = $this->verify(OnlineMediaKind::Button);

        self::assertNotNull($photo->verifiedBytes, 'verified as ever');
        self::assertSame([], $this->transport()->getSent());
    }

    private function verify(OnlineMediaKind $kind): OnlineEntryMedia
    {
        $entry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $photo = $this->evidence($entry, $kind, 1_792_002_832_345);
        self::getContainer()->get(FakeOnlineMediaStorage::class)->upload($photo, $photo->declaredBytes, $photo->contentType);

        // The async handler as the consumer runs it - its own message goes to the queue
        self::getContainer()->get(VerifyOnlineMediaHandler::class)(new VerifyOnlineMedia($photo->id->toString()));
        $this->entityManager()->flush();

        return $photo;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
