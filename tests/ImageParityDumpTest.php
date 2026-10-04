<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Services\GeneratePuzzleQrCode;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * TEMPORARY (base image validation, never merged): writes the real outputs of
 * every image generator into $PARITY_DIR so two base images can be compared
 * pixel by pixel. Skipped unless PARITY_DIR is set.
 */
final class ImageParityDumpTest extends WebTestCase
{
    public function testDumpGeneratorOutputs(): void
    {
        $dir = getenv('PARITY_DIR');
        if (!is_string($dir) || $dir === '') {
            self::markTestSkipped('PARITY_DIR not set');
        }
        @mkdir($dir, 0777, true);

        $client = self::createClient();

        $vouchers = [
            'available' => VoucherFixture::VOUCHER_AVAILABLE,
            'percentage' => VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE,
            'lifetime' => VoucherFixture::VOUCHER_LIFETIME_AVAILABLE,
        ];
        foreach ($vouchers as $name => $id) {
            foreach ([1, 2, 3, 4] as $variant) {
                $client->request('GET', "/voucher/$id/image-$variant.png");
                self::assertResponseIsSuccessful();
                file_put_contents("$dir/voucher-$name-$variant.png", (string) $client->getResponse()->getContent());
            }
        }

        $client->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '/qr-code.png');
        self::assertResponseIsSuccessful();
        file_put_contents("$dir/qr-puzzle.png", (string) $client->getResponse()->getContent());

        /** @var GeneratePuzzleQrCode $qr */
        $qr = self::getContainer()->get(GeneratePuzzleQrCode::class);
        file_put_contents("$dir/qr-ravensburger-url.png", $qr->generateForUrl('https://myspeedpuzzling.com/ravensburger-puzzle-month/1'));

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $times = $database->fetchFirstColumn(
            "SELECT id FROM puzzle_solving_time WHERE finished_puzzle_photo IS NULL AND team IS NULL ORDER BY id LIMIT 2",
        );
        self::assertCount(2, $times);
        [$placeholderTime, $photoTime] = $times;
        assert(is_string($placeholderTime) && is_string($photoTime));

        // One over the placeholder, one over a real photo
        $client->request('GET', '/result-image/' . $placeholderTime);
        self::assertResponseIsSuccessful();
        file_put_contents("$dir/result-placeholder.png", (string) $client->getResponse()->getContent());

        /** @var Filesystem $filesystem */
        $filesystem = self::getContainer()->get(Filesystem::class);
        $playerId = $database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $photoTime]);
        assert(is_string($playerId));
        $photo = "players/$playerId/parity-photo.jpg";
        $filesystem->write($photo, (string) file_get_contents(__DIR__ . '/../public/img/placeholder-puzzle.jpg'));
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET finished_puzzle_photo = :photo WHERE id = :id',
            ['photo' => $photo, 'id' => $photoTime],
        );
        $client->request('GET', '/result-image/' . $photoTime);
        self::assertResponseIsSuccessful();
        file_put_contents("$dir/result-photo.png", (string) $client->getResponse()->getContent());

        // Upload pipeline: the same input files, generated once by the reference image
        $inputs = getenv('PARITY_INPUTS');
        self::assertIsString($inputs);
        /** @var ImageOptimizer $optimizer */
        $optimizer = self::getContainer()->get(ImageOptimizer::class);
        $optimized = 0;
        foreach ((array) glob("$inputs/upload.*") as $input) {
            assert(is_string($input));
            $work = sys_get_temp_dir() . '/parity-' . basename($input);
            copy($input, $work);
            $optimizer->optimize($work);
            copy($work, "$dir/optimized-" . basename($input) . '.out');
            $optimized++;
        }
        self::assertGreaterThanOrEqual(6, $optimized);
    }
}
