<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Imagick;
use ImagickException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\UploadedImageSummariser;
use SpeedPuzzling\Web\Tests\ExifPhotos;
use SpeedPuzzling\Web\Value\ImageSoftwareFamily;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use Stringable;

/**
 * docs/features/upload-image-metadata.md - what is kept of an uploaded file's metadata, and that nothing else is.
 */
final class UploadedImageSummariserTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function testAPhonePhotoKeepsTheAllowedFieldsOnly(): void
    {
        $content = ExifPhotos::iphoneJpeg(64, 48);
        $summary = new UploadedImageSummariser()->summarise($this->file($content));

        self::assertSame(strlen($content), $summary->bytes);
        self::assertSame('JPEG', $summary->format);
        self::assertSame(64, $summary->width);
        self::assertSame(48, $summary->height);
        self::assertTrue($summary->exif);
        self::assertTrue($summary->cameraExif);
        self::assertSame('2026-10-21T14:03:12', $summary->capturedAt);
        self::assertSame('+02:00', $summary->capturedAtOffset);
        self::assertSame(ImageSoftwareFamily::Phone, $summary->software);
        self::assertSame('Apple', $summary->make);
        self::assertSame(6, $summary->orientation);
        self::assertSame(UploadedImageMetadata::VERSION, $summary->version);
    }

    public function testGpsSerialNumbersOwnerCommentModelMakerNoteAndThumbnailAreNeverKept(): void
    {
        $summary = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::iphoneJpeg()));
        $stored = (string) json_encode($summary->toArray());

        $decoded = json_decode($stored, true);
        self::assertIsArray($decoded);
        self::assertSame(
            ['version', 'bytes', 'format', 'width', 'height', 'exif', 'cameraExif', 'capturedAt', 'capturedAtOffset', 'software', 'make', 'orientation'],
            array_keys($decoded),
        );

        foreach ([ExifPhotos::SERIAL_NUMBER, ExifPhotos::LENS_SERIAL_NUMBER, ExifPhotos::OWNER, ExifPhotos::USER_COMMENT, ExifPhotos::MODEL, ExifPhotos::MAKER_NOTE, '17.4.1', 'iPhone'] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }

        // Only the fields above, each with a value of its own: nothing of the GPS IFD can ride along
        self::assertStringNotContainsStringIgnoringCase('gps', $stored);
        self::assertSame(
            ['exif' => true, 'cameraExif' => true, 'capturedAt' => '2026-10-21T14:03:12', 'capturedAtOffset' => '+02:00', 'software' => 'phone', 'make' => 'Apple', 'orientation' => 6],
            array_intersect_key($summary->toArray(), array_flip(['exif', 'cameraExif', 'capturedAt', 'capturedAtOffset', 'software', 'make', 'orientation'])),
        );
    }

    public function testABigEndianCameraPhotoWithoutAnOffset(): void
    {
        $summary = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::jpeg(40, 30, ExifPhotos::nikonExif())));

        self::assertTrue($summary->cameraExif);
        self::assertSame('Nikon', $summary->make);
        self::assertSame(ImageSoftwareFamily::Camera, $summary->software);
        self::assertSame('2026-03-14T09:26:53', $summary->capturedAt);
        self::assertNull($summary->capturedAtOffset, 'No zone is guessed');
        self::assertSame(1, $summary->orientation);
        self::assertStringNotContainsString(ExifPhotos::SERIAL_NUMBER, (string) json_encode($summary->toArray()));
    }

    public function testTheSameBlockReadsTheSameInEitherByteOrder(): void
    {
        $littleEndian = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::jpeg(40, 30, ExifPhotos::iphoneExif())));
        $bigEndian = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::jpeg(40, 30, ExifPhotos::iphoneExif(bigEndian: true))));

        self::assertEquals(
            array_diff_key($littleEndian->toArray(), ['bytes' => true]),
            array_diff_key($bigEndian->toArray(), ['bytes' => true]),
        );
    }

    public function testAScreenshotPngWithItsEXifChunk(): void
    {
        $summary = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::png(30, 20, ExifPhotos::screenshotExif()), 'png'));

        self::assertSame('PNG', $summary->format);
        self::assertTrue($summary->exif);
        self::assertFalse($summary->cameraExif);
        self::assertSame(ImageSoftwareFamily::Screenshot, $summary->software);
        self::assertNull($summary->make);
        self::assertNull($summary->capturedAt);
    }

    public function testAWebpWithAnExifChunk(): void
    {
        $summary = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::webp(30, 20, ExifPhotos::iphoneExif()), 'webp'));

        self::assertSame('WEBP', $summary->format);
        self::assertSame(30, $summary->width);
        self::assertTrue($summary->cameraExif);
        self::assertSame('2026-10-21T14:03:12', $summary->capturedAt);
        self::assertSame('Apple', $summary->make);
    }

    public function testAnEditorsExportWithAClockNeverSet(): void
    {
        $summary = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::jpeg(20, 20, ExifPhotos::editorExif())));

        self::assertTrue($summary->exif);
        self::assertFalse($summary->cameraExif, 'A zero capture time is no camera evidence');
        self::assertSame(ImageSoftwareFamily::Editor, $summary->software);
        self::assertNull($summary->capturedAt);
        self::assertNull($summary->make);
    }

    public function testAPictureWithoutExif(): void
    {
        $content = ExifPhotos::jpeg(50, 25, null);
        $summary = new UploadedImageSummariser()->summarise($this->file($content));

        self::assertEquals(
            new UploadedImageMetadata(bytes: strlen($content), format: 'JPEG', width: 50, height: 25, exif: false, cameraExif: false),
            $summary,
        );
    }

    public function testABrokenExifBlockStillGivesTheFileFacts(): void
    {
        // A TIFF header pointing past its end - what a damaged upload can carry
        $summary = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::jpeg(20, 10, "II*\0" . pack('V', 4096) . 'xx')));

        self::assertSame('JPEG', $summary->format);
        self::assertSame(20, $summary->width);
        self::assertFalse($summary->cameraExif);
        self::assertNull($summary->capturedAt);
    }

    public function testTheSummaryReadsBackFromItsStoredForm(): void
    {
        $summary = new UploadedImageSummariser()->summarise($this->file(ExifPhotos::iphoneJpeg()));

        $decoded = json_decode((string) json_encode($summary->toArray()), true);
        self::assertIsArray($decoded);
        self::assertEquals($summary, UploadedImageMetadata::fromArray($decoded));
        self::assertNull(UploadedImageMetadata::fromArray(null));
    }

    /**
     * @return iterable<string, array{null|string, null|string, null|string, null|ImageSoftwareFamily}>
     */
    public static function softwareTags(): iterable
    {
        yield 'iPhone' => ['17.4.1', 'Apple', null, ImageSoftwareFamily::Phone];
        yield 'Samsung firmware' => ['G998BXXU5CVDD', 'Samsung', null, ImageSoftwareFamily::Phone];
        yield 'Canon firmware' => ['Firmware Version 1.1.0', 'Canon', null, ImageSoftwareFamily::Camera];
        yield 'Photoshop' => ['Adobe Photoshop 25.0 (Windows)', 'Canon', null, ImageSoftwareFamily::Editor];
        yield 'GIMP' => ['GIMP 2.10.36', null, null, ImageSoftwareFamily::Editor];
        yield 'Snapseed' => ['Snapseed 2.0', 'Apple', null, ImageSoftwareFamily::Editor];
        yield 'macOS Photos' => ['Photos 9.0', 'Apple', null, ImageSoftwareFamily::Editor];
        yield 'iOS screenshot' => [null, null, 'Screenshot', ImageSoftwareFamily::Screenshot];
        yield 'screenshot tool' => ['ShareX', null, null, ImageSoftwareFamily::Screenshot];
        yield 'something else' => ['MyCustomTool by someone', null, null, ImageSoftwareFamily::Unknown];
        yield 'no Software tag' => [null, 'Apple', null, null];
    }

    #[DataProvider('softwareTags')]
    public function testSoftwareFamilies(null|string $software, null|string $make, null|string $userComment, null|ImageSoftwareFamily $family): void
    {
        self::assertSame($family, UploadedImageSummariser::softwareFamily($software, $make, $userComment));
    }

    public function testMakesAreBrandsOnly(): void
    {
        self::assertSame('Samsung', UploadedImageSummariser::make('samsung'));
        self::assertSame('Nikon', UploadedImageSummariser::make('NIKON CORPORATION'));
        self::assertSame('OM System', UploadedImageSummariser::make('OLYMPUS IMAGING CORP.'));
        self::assertSame('LG', UploadedImageSummariser::make('LGE'));
        self::assertSame(UploadedImageSummariser::OTHER_MAKE, UploadedImageSummariser::make('Alex Example\'s camera'));
        self::assertNull(UploadedImageSummariser::make(null));
    }

    public function testTheOptimizerAnswersTheSummaryOfTheFileBeforeItIsStripped(): void
    {
        $content = ExifPhotos::iphoneJpeg(64, 48);
        $path = $this->file($content);

        $summary = new ImageOptimizer(new NullLogger())->optimize($path);

        self::assertNotNull($summary);
        self::assertSame(strlen($content), $summary->bytes, 'The size as uploaded, not as stored');
        self::assertSame('2026-10-21T14:03:12', $summary->capturedAt);

        // The stored file has lost the EXIF the summary was read from
        $stored = new Imagick($path);
        self::assertSame([], $stored->getImageProperties('exif:*'));
        $stored->clear();
    }

    public function testAFileThatCannotBeSummarisedIsLoggedAtInfoAndNothingIsRecorded(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            /**
             * @param array<mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
        $path = $this->file('this is not an image', 'jpg');

        try {
            new ImageOptimizer($logger)->optimize($path);
            self::fail('A file that is no image still fails the upload as before');
        } catch (ImagickException) {
        }

        self::assertSame(['info'], array_column($logger->records, 'level'));
        self::assertSame('Could not read the metadata summary of an uploaded image', $logger->records[0]['message']);
    }

    private function file(string $content, string $extension = 'jpg'): string
    {
        $path = ExifPhotos::file($content, $extension);
        $this->files[] = $path;

        return $path;
    }
}
