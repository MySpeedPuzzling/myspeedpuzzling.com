<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * crop_trust analyses the image pipeline would have pushed (docs/features/insights/image-taxonomy.md) for the
 * "Puzzles needing improvement" tests. Fixture puzzles have no picture, so each gets one first.
 */
trait SeedsCropTrustAnalyses
{
    protected static function seedImageMarks(Connection $database): void
    {
        $lowResolution = ['low_resolution_artwork'];

        self::seedCropTrust($database, PuzzleFixture::PUZZLE_300, 'improve-300.jpg', ['resolution' => $lowResolution, 'notThePicture' => false]);
        self::seedCropTrust($database, PuzzleFixture::PUZZLE_500_02, 'improve-500-02.jpg', ['resolution' => ['small_original'], 'notThePicture' => false]);
        self::seedCropTrust($database, PuzzleFixture::PUZZLE_2000, 'improve-2000.jpg', ['resolution' => $lowResolution, 'notThePicture' => false]);
        self::seedCropTrust($database, PuzzleFixture::PUZZLE_1500_01, 'improve-1500-01.jpg', ['resolution' => null, 'notThePicture' => true]);
        self::seedCropTrust($database, PuzzleFixture::PUZZLE_3000, 'improve-3000.jpg', ['resolution' => null, 'notThePicture' => false]);
        // The mark describes the picture PUZZLE_6000 had before
        self::seedCropTrust($database, PuzzleFixture::PUZZLE_6000, 'improve-6000-new.jpg', ['resolution' => $lowResolution, 'notThePicture' => true], analysedImage: 'improve-6000-old.jpg');
    }

    /**
     * @param array<string, mixed> $marks
     */
    protected static function seedCropTrust(Connection $database, string $puzzleId, string $image, array $marks, null|string $analysedImage = null): void
    {
        $database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);

        $result = json_encode($marks + ['trust' => 0.42, 'cropNeedsLook' => true, 'pictureKind' => 'photo', 'kind' => 'box_front'], JSON_THROW_ON_ERROR);
        $now = TestClock::SQL_NOW;

        $database->executeStatement(
            <<<SQL
INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
VALUES (:id, :puzzleId, 'crop_trust', '1.0.0', :fingerprint, CAST(:result AS jsonb), NULL, {$now})
ON CONFLICT (analyzer, puzzle_id) DO UPDATE SET image_fingerprint = EXCLUDED.image_fingerprint, result = EXCLUDED.result
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'puzzleId' => $puzzleId,
                'fingerprint' => PuzzleImageFingerprint::of($analysedImage ?? $image),
                'result' => $result,
            ],
        );
    }
}
