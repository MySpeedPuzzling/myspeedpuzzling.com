<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Imagick;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Tests\ImagesWithMetadata;

final class ImageOptimizerTest extends TestCase
{
    private ImageOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->optimizer = new ImageOptimizer(new NullLogger());
    }

    public function testGetImageRatioForSquareImage(): void
    {
        $path = $this->createTestImage(100, 100);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(1.0, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioForPortraitImage(): void
    {
        $path = $this->createTestImage(135, 200);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(0.675, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioForLandscapeImage(): void
    {
        $path = $this->createTestImage(200, 135);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(1.481, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioSwapsDimensionsForRotatedExifOrientation(): void
    {
        // 200x100 stored pixels with EXIF orientation 6 (rotate 90° CW) render as 100x200
        $path = $this->createTestImage(200, 100, exifOrientation: 6);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(0.5, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioKeepsDimensionsForUprightExifOrientation(): void
    {
        // Orientation 3 (rotate 180°) does not swap width/height
        $path = $this->createTestImage(200, 100, exifOrientation: 3);

        try {
            $ratio = $this->optimizer->getImageRatio($path);
            self::assertEqualsWithDelta(2.0, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioFromBlob(): void
    {
        $path = $this->createTestImage(135, 200);

        try {
            $content = file_get_contents($path);
            assert(is_string($content));

            $ratio = $this->optimizer->getImageRatioFromBlob($content);
            self::assertEqualsWithDelta(0.675, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testGetImageRatioFromBlobSwapsDimensionsForRotatedExifOrientation(): void
    {
        $path = $this->createTestImage(200, 100, exifOrientation: 8);

        try {
            $content = file_get_contents($path);
            assert(is_string($content));

            $ratio = $this->optimizer->getImageRatioFromBlob($content);
            self::assertEqualsWithDelta(0.5, $ratio, 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testPhotoThatFitsLosesItsGpsCameraAndCommentButKeepsItsColourProfile(): void
    {
        $path = $this->file(ImagesWithMetadata::jpeg(400, 300), 'jpg');
        $before = $this->exif($path);
        self::assertArrayHasKey('GPSLatitude', $before, 'the test photo carries a GPS position');
        self::assertSame(ImagesWithMetadata::CAMERA, $before['Make'] ?? null);

        try {
            $this->optimizer->optimize($path);

            $after = $this->exif($path);
            self::assertArrayNotHasKey('GPSLatitude', $after);
            self::assertArrayNotHasKey('Make', $after);

            $imagick = new Imagick($path);
            self::assertSame(['icc'], $imagick->getImageProfiles('*', false));
            self::assertSame(ImagesWithMetadata::colourProfile(), $imagick->getImageProfiles('icc')['icc'] ?? null);
            self::assertSame([], $imagick->getImageProperties('comment', false));
            self::assertSame([400, 300], [$imagick->getImageWidth(), $imagick->getImageHeight()]);
            // Written again at the photo's own quality - it looks and weighs the same
            self::assertSame(92, $imagick->getImageCompressionQuality());
        } finally {
            unlink($path);
        }
    }

    public function testOrientationIsAppliedToThePixelsBeforeItIsDropped(): void
    {
        // 200x100 stored, EXIF orientation 6 = shown rotated 90° clockwise: the red top-left corner ends up top-right
        $path = $this->file(ImagesWithMetadata::jpeg(200, 100, orientation: 6), 'jpg');

        try {
            $this->optimizer->optimize($path);

            $imagick = new Imagick($path);
            self::assertSame([100, 200], [$imagick->getImageWidth(), $imagick->getImageHeight()]);
            self::assertContains($imagick->getImageOrientation(), [Imagick::ORIENTATION_UNDEFINED, Imagick::ORIENTATION_TOPLEFT]);
            self::assertArrayNotHasKey('Orientation', $this->exif($path));
            self::assertRed($imagick, 95, 5);
            self::assertNotRed($imagick, 5, 5);
            self::assertEqualsWithDelta(0.5, $this->optimizer->getImageRatio($path), 0.001);
        } finally {
            unlink($path);
        }
    }

    public function testPhotoWithoutMetadataThatFitsIsStoredAsUploaded(): void
    {
        // 1200 px: ImageMagick 6 used to decode it at 2x under the JPEG size hint and store it upscaled to 2000 px
        $upload = ImagesWithMetadata::jpeg(1200, 900, exif: false, comment: null, colourProfile: false);
        $path = $this->file($upload, 'jpg');

        try {
            $this->optimizer->optimize($path);

            self::assertSame($upload, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testPhotoWithMetadataThatFitsKeepsItsSize(): void
    {
        $path = $this->file(ImagesWithMetadata::jpeg(1200, 900), 'jpg');

        try {
            $this->optimizer->optimize($path);

            $imagick = new Imagick($path);
            self::assertSame([1200, 900], [$imagick->getImageWidth(), $imagick->getImageHeight()]);
            self::assertArrayNotHasKey('GPSLatitude', $this->exif($path));
        } finally {
            unlink($path);
        }
    }

    public function testDownscaledPhotoLosesItsGpsAndKeepsItsColourProfile(): void
    {
        $path = $this->file(ImagesWithMetadata::jpeg(2400, 1200), 'jpg');

        try {
            $this->optimizer->optimize($path);

            $imagick = new Imagick($path);
            self::assertSame([2000, 1000], [$imagick->getImageWidth(), $imagick->getImageHeight()]);
            self::assertSame(['icc'], $imagick->getImageProfiles('*', false));
            self::assertArrayNotHasKey('GPSLatitude', $this->exif($path));
        } finally {
            unlink($path);
        }
    }

    public function testPngLosesItsExifAndTextChunksWithoutAPixelChanging(): void
    {
        $upload = ImagesWithMetadata::png(300, 200);
        self::assertContains('eXIf', ImagesWithMetadata::pngChunkTypes($upload));
        $path = $this->file($upload, 'png');
        $pixelsBefore = (new Imagick($path))->getImageSignature();

        try {
            $this->optimizer->optimize($path);

            $stored = (string) file_get_contents($path);
            self::assertSame([], array_values(array_intersect(
                ImagesWithMetadata::pngChunkTypes($stored),
                ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'],
            )));
            self::assertStringNotContainsString(ImagesWithMetadata::CAMERA, $stored);
            self::assertSame($pixelsBefore, (new Imagick($path))->getImageSignature());
        } finally {
            unlink($path);
        }
    }

    public function testLosslessWebpLosesItsExifAndStaysLossless(): void
    {
        $upload = ImagesWithMetadata::losslessWebp(300, 200);
        self::assertContains('EXIF', ImagesWithMetadata::webpChunkTypes($upload));
        $path = $this->file($upload, 'webp');
        $pixelsBefore = (new Imagick($path))->getImageSignature();

        try {
            $this->optimizer->optimize($path);

            $stored = (string) file_get_contents($path);
            $chunks = ImagesWithMetadata::webpChunkTypes($stored);
            self::assertNotContains('EXIF', $chunks);
            self::assertNotContains('XMP ', $chunks);
            self::assertContains('VP8L', $chunks, 'still lossless');
            self::assertSame($pixelsBefore, (new Imagick($path))->getImageSignature());
        } finally {
            unlink($path);
        }
    }

    public function testAnimationThatFitsIsKeptAsUploaded(): void
    {
        $animation = new Imagick();

        foreach (['red', 'blue'] as $colour) {
            $frame = new Imagick();
            $frame->newImage(40, 30, $colour);
            $frame->setImageFormat('gif');
            $animation->addImage($frame);
        }

        $animation->commentImage('frame comment');
        $upload = $animation->getImagesBlob();
        $path = $this->file($upload, 'gif');

        try {
            $this->optimizer->optimize($path);

            self::assertSame($upload, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    private function file(string $content, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_img_') . '.' . $extension;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array<mixed>
     */
    private function exif(string $path): array
    {
        $data = @exif_read_data($path);

        return is_array($data) ? $data : [];
    }

    private static function assertRed(Imagick $imagick, int $x, int $y): void
    {
        $pixel = $imagick->getImagePixelColor($x, $y)->getColor();
        self::assertGreaterThan(200, $pixel['r'], "pixel $x,$y is red");
        self::assertLessThan(60, $pixel['g'], "pixel $x,$y is red");
    }

    private static function assertNotRed(Imagick $imagick, int $x, int $y): void
    {
        $pixel = $imagick->getImagePixelColor($x, $y)->getColor();
        self::assertFalse($pixel['r'] > 200 && $pixel['g'] < 60, "pixel $x,$y is not red");
    }

    private function createTestImage(int $width, int $height, null|int $exifOrientation = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_img_') . '.jpg';

        $imagick = new Imagick();
        $imagick->newImage($width, $height, 'white');
        $imagick->setImageFormat('jpeg');
        $data = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        // Imagick cannot write an EXIF profile into a fresh image,
        // so splice a minimal APP1 segment with the orientation tag manually
        if ($exifOrientation !== null) {
            $exif = "Exif\0\0" . "II*\0" . pack('V', 8)
                . pack('v', 1)
                . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $exifOrientation) . pack('v', 0)
                . pack('V', 0);
            $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
            $data = substr($data, 0, 2) . $app1 . substr($data, 2);
        }

        file_put_contents($path, $data);

        return $path;
    }
}
