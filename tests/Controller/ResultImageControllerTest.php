<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Services\ResultImageCache;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\ImagesWithMetadata;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The result share image is fetched by social crawlers: a missing photo must never
 * answer 500 (Sentry WEB-CT) - the image is drawn over the placeholder, and not kept.
 */
final class ResultImageControllerTest extends WebTestCase
{
    // TIME_06: solo time of PLAYER_REGULAR (public profile, name "John Doe")
    private const string TIME = PuzzleSolvingTimeFixture::TIME_06;
    private const string PHOTO = 'players/' . PlayerFixture::PLAYER_REGULAR . '/finished-for-card.jpg';
    private const string CARD = 'players/' . PlayerFixture::PLAYER_REGULAR . '/results/' . self::TIME . '.png';
    private const string HIDDEN_CARD = 'players/' . PlayerFixture::PLAYER_REGULAR . '/results/' . self::TIME . '-hidden.png';

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingPhotos(): iterable
    {
        yield 'puzzle without any image' => ['UPDATE puzzle SET image = NULL, hide_image_until = NULL WHERE id = :puzzleId'];
        yield 'photo known to the database, gone from storage' => ["UPDATE puzzle SET image = 'gone-from-storage.jpg', hide_image_until = NULL WHERE id = :puzzleId"];
        yield 'image hidden until a round starts' => ["UPDATE puzzle SET image = 'hidden.jpg', hide_image_until = " . TestClock::SQL_NOW . " + INTERVAL '1 day' WHERE id = :puzzleId"];
    }

    public function testTheShareImageCarriesNoneOfThePhotosMetadata(): void
    {
        $client = self::createClient();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $time = $database->fetchAssociative(
            'SELECT id, player_id FROM puzzle_solving_time WHERE finished_puzzle_photo IS NULL ORDER BY id LIMIT 1',
        );
        self::assertIsArray($time);
        $timeId = $time['id'];
        $playerId = $time['player_id'];
        assert(is_string($timeId) && is_string($playerId));

        // A phone photo taken at home: GPS position, camera, comment
        $photo = "players/$playerId/finished-with-gps.jpg";
        /** @var Filesystem $filesystem */
        $filesystem = self::getContainer()->get(Filesystem::class);
        $filesystem->write($photo, ImagesWithMetadata::jpeg(600, 400));
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET finished_puzzle_photo = :photo WHERE id = :id',
            ['photo' => $photo, 'id' => $timeId],
        );

        $client->request('GET', '/result-image/' . $timeId);

        self::assertResponseIsSuccessful();
        $png = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("\x89PNG", $png);
        self::assertSame([], array_values(array_intersect(
            ImagesWithMetadata::pngChunkTypes($png),
            ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'],
        )));
        self::assertStringNotContainsString(ImagesWithMetadata::CAMERA, $png);
    }

    #[DataProvider('missingPhotos')]
    public function testAMissingPhotoIsDrawnOverThePlaceholderAndNotKept(string $sql): void
    {
        $client = self::createClient();

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $time = $database->fetchAssociative(
            'SELECT id, puzzle_id, player_id FROM puzzle_solving_time WHERE finished_puzzle_photo IS NULL ORDER BY id LIMIT 1',
        );
        self::assertIsArray($time);
        $timeId = $time['id'];
        $playerId = $time['player_id'];
        assert(is_string($timeId) && is_string($playerId));
        $database->executeStatement($sql, ['puzzleId' => $time['puzzle_id']]);

        $client->request('GET', '/result-image/' . $timeId);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertStringStartsWith("\x89PNG", (string) $client->getResponse()->getContent());

        /** @var Filesystem $filesystem */
        $filesystem = self::getContainer()->get(Filesystem::class);
        self::assertFalse(
            $filesystem->fileExists("players/$playerId/results/$timeId.png"),
            'a placeholder version is never stored',
        );
    }

    public function testTheCardIsAnInlinePng(): void
    {
        $client = self::createClient();
        $this->givePhotoToTheTime();

        $client->request('GET', '/result-image/' . self::TIME);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('Content-Disposition', 'inline');
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", (string) $client->getResponse()->getContent());
    }

    public function testAKeptCardIsServedWithoutDrawing(): void
    {
        $client = self::createClient();
        // One container for both requests: the kept cards live in its in-memory storage
        $client->disableReboot();
        $this->givePhotoToTheTime();

        $client->request('GET', '/result-image/' . self::TIME);
        self::assertResponseIsSuccessful();
        $first = (string) $client->getResponse()->getContent();

        // Nothing to draw from any more - only the kept card can answer
        self::getContainer()->get(Filesystem::class)->delete(self::PHOTO);

        $client->request('GET', '/result-image/' . self::TIME);
        self::assertResponseIsSuccessful();
        self::assertSame(md5($first), md5((string) $client->getResponse()->getContent()));
        self::assertFalse($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function testAPrivateProfilesCardIsKeptApartAndNamesNobody(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->givePhotoToTheTime();
        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        $client->request('GET', '/result-image/' . self::TIME);
        self::assertResponseIsSuccessful();
        $nameless = (string) $client->getResponse()->getContent();

        $cache = self::getContainer()->get(ResultImageCache::class);
        self::assertSame(md5($nameless), md5((string) $cache->read(self::HIDDEN_CARD)));
        self::assertNull($cache->read(self::CARD), 'the named card is never written for a private profile');

        $database->executeStatement('UPDATE player SET is_private = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        // The next request reads the player afresh, as a new request in production does
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        $client->request('GET', '/result-image/' . self::TIME);
        self::assertResponseIsSuccessful();
        $named = (string) $client->getResponse()->getContent();

        self::assertNotSame(md5($nameless), md5($named), 'the card kept while private is not served once public');
        self::assertSame(md5($named), md5((string) $cache->read(self::CARD)));
    }

    private function givePhotoToTheTime(): void
    {
        $photo = file_get_contents(__DIR__ . '/../fixtures/result-image/photo.jpg');
        self::assertIsString($photo);
        self::getContainer()->get(Filesystem::class)->write(self::PHOTO, $photo);
        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET finished_puzzle_photo = :photo WHERE id = :id',
            ['photo' => self::PHOTO, 'id' => self::TIME],
        );
    }
}
