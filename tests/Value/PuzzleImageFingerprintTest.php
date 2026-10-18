<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The image list filters by the fingerprint in SQL, the write checks it in PHP - both must give the same answer.
 */
final class PuzzleImageFingerprintTest extends KernelTestCase
{
    public function testSqlAndPhpAgree(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);

        foreach (['ravensburger-cats-500-018d0003-0a1b2c3d.jpg', 'žluťoučký-kůň-1000.jpeg', '0f1e2d3c4b5a69788796a5b4c3d2e1f0.webp'] as $path) {
            self::assertSame(
                PuzzleImageFingerprint::of($path),
                $database->fetchOne('SELECT ' . PuzzleImageFingerprint::sql('CAST(:path AS TEXT)'), ['path' => $path]),
            );
        }

        self::assertSame(16, strlen(PuzzleImageFingerprint::of('a.jpg')));
        self::assertNotSame(PuzzleImageFingerprint::of('a.jpg'), PuzzleImageFingerprint::of('b.jpg'));
    }
}
