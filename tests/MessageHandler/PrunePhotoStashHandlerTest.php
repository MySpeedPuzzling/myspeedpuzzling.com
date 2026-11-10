<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\PrunePhotoStash;
use SpeedPuzzling\Web\Message\RecordPhotoStashEntry;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class PrunePhotoStashHandlerTest extends KernelTestCase
{
    public function testExpiredDescriptionsGoFreshOnesStay(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $now = TestClock::now();
        $expired = $now->modify('-' . (PhotoStash::KEEP_HOURS + 1) . ' hours');
        $fresh = $now->modify('-' . (PhotoStash::KEEP_HOURS - 1) . ' hours');

        $bus->dispatch(new RecordPhotoStashEntry(str_repeat('a', 32), PlayerFixture::PLAYER_REGULAR, 'old.jpg', 'image/jpeg', false, 1000, null, $expired, $now));
        $bus->dispatch(new RecordPhotoStashEntry(str_repeat('b', 32), PlayerFixture::PLAYER_REGULAR, 'new.jpg', 'image/jpeg', false, 1000, null, $fresh, $fresh));

        $bus->dispatch(new PrunePhotoStash());

        self::assertSame(
            [str_repeat('b', 32)],
            self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT token FROM photo_stash_entry'),
            'Expiry is when it was stored - a later preview does not keep it',
        );
    }
}
