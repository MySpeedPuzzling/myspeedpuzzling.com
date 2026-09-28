<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The result share image is fetched by social crawlers: a missing photo must never
 * answer 500 (Sentry WEB-CT) - the image is drawn over the placeholder, and not kept.
 */
final class ResultImageControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function missingPhotos(): iterable
    {
        yield 'puzzle without any image' => ['UPDATE puzzle SET image = NULL, hide_image_until = NULL WHERE id = :puzzleId'];
        yield 'photo known to the database, gone from storage' => ["UPDATE puzzle SET image = 'gone-from-storage.jpg', hide_image_until = NULL WHERE id = :puzzleId"];
        yield 'image hidden until a round starts' => ["UPDATE puzzle SET image = 'hidden.jpg', hide_image_until = NOW() + INTERVAL '1 day' WHERE id = :puzzleId"];
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
}
