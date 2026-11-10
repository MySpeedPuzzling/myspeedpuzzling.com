<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Message\BackfillPhotoStashEntries;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\PhotoStashBackfillResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Release 1 of 2 (docs/TODO.md "PhotoStash descriptions in Postgres")
 */
final class BackfillPhotoStashEntriesHandlerTest extends KernelTestCase
{
    private const string VALID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string KEPT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testTheStillValidOldDescriptionsGetTheirRows(): void
    {
        self::bootKernel();
        $now = TestClock::now();
        $player = PlayerFixture::PLAYER_REGULAR;
        $stash = self::getContainer()->get(PhotoStash::class);

        $this->write($player, self::VALID, ['name' => 'box.jpg', 'mime' => 'image/jpeg', 'preview' => false, 'bytes' => 1234, 'storedAt' => $now->modify('-2 hours')->format(DATE_ATOM), 'browserMetadata' => null]);
        $this->write($player, self::KEPT, ['name' => 'kept.heic', 'mime' => 'image/heic', 'preview' => true, 'storedAt' => $now->modify('-1 hour')->format(DATE_ATOM)]);
        $this->write($player, str_repeat('c', 32), ['name' => 'mirror.jpg', 'mime' => 'image/jpeg', 'preview' => false, 'bytes' => 1, 'storedAt' => $now->format(DATE_ATOM), 'mirror' => true]);
        $this->write($player, str_repeat('d', 32), ['name' => 'old.jpg', 'mime' => 'image/jpeg', 'storedAt' => $now->modify('-25 hours')->format(DATE_ATOM)]);
        $this->write($player, str_repeat('e', 32), ['name' => 'broken.jpg']);
        $this->write('018d0000-0000-0000-0000-00000000dead', str_repeat('f', 32), ['name' => 'gone.jpg', 'mime' => 'image/jpeg', 'storedAt' => $now->format(DATE_ATOM)]);
        // Not a description
        self::getContainer()->get(Filesystem::class)->write('tmp-uploads/' . $player . '/' . self::VALID, 'photo');

        $dryRun = $this->backfill(false);
        self::assertSame([6, 1, 1, 1, 0, 3, 0, 0], self::counts($dryRun));
        self::assertSame([], $this->tokens(), 'A dry run writes nothing');

        $written = $this->backfill(true);
        self::assertSame([6, 1, 1, 1, 0, 3, 2, 1], self::counts($written), 'The deleted player\'s photo fails');
        self::assertSame([self::VALID, self::KEPT], $this->tokens());

        // The rows answer exactly as the old descriptions did - with the descriptions gone
        self::getContainer()->get(Filesystem::class)->delete('tmp-uploads/' . $player . '/' . self::VALID . '.json');
        self::getContainer()->get(Filesystem::class)->delete('tmp-uploads/' . $player . '/' . self::KEPT . '.json');
        self::assertSame(1234, $stash->reservedBytes(self::VALID, $player));
        self::assertSame(['kept.heic', true], [$stash->describe(self::KEPT, $player)?->fileName, $stash->describe(self::KEPT, $player)?->hasPreview]);
        self::assertNull($stash->reservedBytes(self::KEPT, $player));
    }

    public function testRunningItAgainChangesNothing(): void
    {
        self::bootKernel();
        $this->write(PlayerFixture::PLAYER_REGULAR, self::VALID, ['name' => 'box.jpg', 'mime' => 'image/jpeg', 'bytes' => 1234, 'storedAt' => TestClock::now()->format(DATE_ATOM)]);

        self::assertSame(1, $this->backfill(true)->recorded);

        $again = $this->backfill(true);
        self::assertSame([1, 0, 0], [$again->alreadyRecorded, $again->toRecord, $again->recorded]);
        self::assertSame([self::VALID], $this->tokens());
    }

    private function backfill(bool $write): PhotoStashBackfillResult
    {
        $result = self::getContainer()->get(MessageBusInterface::class)
            ->dispatch(new BackfillPhotoStashEntries($write))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PhotoStashBackfillResult::class, $result);

        return $result;
    }

    /**
     * @return list<int>
     */
    private static function counts(PhotoStashBackfillResult $result): array
    {
        return [$result->descriptions, $result->mirrors, $result->expired, $result->broken, $result->alreadyRecorded, $result->toRecord, $result->recorded, $result->failed];
    }

    /**
     * @param array<string, mixed> $description
     */
    private function write(string $playerId, string $token, array $description): void
    {
        self::getContainer()->get(Filesystem::class)->write('tmp-uploads/' . $playerId . '/' . $token . '.json', (string) json_encode($description));
    }

    /**
     * @return list<string>
     */
    private function tokens(): array
    {
        /** @var list<string> */
        return self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT token FROM photo_stash_entry ORDER BY token');
    }
}
