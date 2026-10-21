<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\BrowserImageSummary;
use SpeedPuzzling\Web\Services\UploadedImageSummariser;
use SpeedPuzzling\Web\Value\UploadedImageMetadataSource;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * docs/features/upload-image-metadata.md "Read in the browser" - the add/edit-time form and multiscan shrink photos in
 * the browser, which drops all EXIF, so the browser reads the allow-list first (assets/photo_metadata.js). Runs the real
 * module under node on synthetic photos (tests/ExifPhotos.php - GPS, serial numbers, owner, user comment, model, maker
 * note and a thumbnail in every phone photo) and checks that:
 *
 * - what the browser sends, once BrowserImageSummary accepted it, is what the server reads from the same file itself
 * - nothing outside the allow-list is ever sent: no GPS, no serial, no owner, no comment, no model, no maker note
 * - whatever it cannot read gives null, never an error
 */
final class PhotoMetadataParityTest extends TestCase
{
    // Everything the browser may send (BrowserImageSummary reads only these)
    private const array WIRE_KEYS = ['bytes', 'format', 'width', 'height', 'exif', 'cameraExif', 'dateTimeOriginal', 'offsetTimeOriginal', 'software', 'make', 'orientation', 'screenshot'];

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

    public function testTheBrowserReadsAJpegLikeTheServer(): void
    {
        $photos = [
            'iPhone, little-endian' => ExifPhotos::iphoneJpeg(64, 48),
            'iPhone, big-endian' => ExifPhotos::jpeg(80, 60, ExifPhotos::iphoneExif(bigEndian: true)),
            'camera without an offset' => ExifPhotos::jpeg(72, 40, ExifPhotos::nikonExif()),
            'screenshot' => ExifPhotos::jpeg(50, 90, ExifPhotos::screenshotExif()),
            'editor export, clock never set' => ExifPhotos::jpeg(30, 30, ExifPhotos::editorExif()),
            'no EXIF at all' => ExifPhotos::jpeg(40, 30, null),
        ];

        $sent = $this->runInNode(array_values($photos));
        $summariser = new UploadedImageSummariser();
        $index = 0;

        foreach ($photos as $label => $content) {
            $wire = $sent[$index++];
            self::assertIsArray($wire, $label);

            $browser = BrowserImageSummary::fromInput($wire);
            self::assertNotNull($browser, $label . ': the server accepts what its own page sends');
            self::assertSame(UploadedImageMetadataSource::Browser, $browser->source);

            $server = $summariser->summarise($this->file($content));

            self::assertSame(
                array_diff_key($server->toArray(), ['source' => true]),
                array_diff_key($browser->toArray(), ['source' => true]),
                $label,
            );
        }
    }

    public function testNothingOutsideTheAllowListLeavesTheBrowser(): void
    {
        [$jpeg, $bigEndian, $heic] = $this->runInNode([
            ExifPhotos::iphoneJpeg(),
            ExifPhotos::jpeg(64, 48, ExifPhotos::iphoneExif(bigEndian: true)),
            ExifPhotos::heic(64, 48, ExifPhotos::iphoneExif()),
        ]);

        foreach ([$jpeg, $bigEndian, $heic] as $wire) {
            self::assertIsArray($wire);
            self::assertSame([], array_diff(array_keys($wire), self::WIRE_KEYS));

            $json = (string) json_encode($wire);

            foreach ([ExifPhotos::SERIAL_NUMBER, ExifPhotos::LENS_SERIAL_NUMBER, ExifPhotos::OWNER, ExifPhotos::USER_COMMENT, ExifPhotos::MODEL, ExifPhotos::MAKER_NOTE, 'iPhone', 'gps', 'birthday', 'grandma'] as $secret) {
                self::assertStringNotContainsStringIgnoringCase($secret, $json);
            }

            // Not a coordinate either - 50° 5' 15" N, 14° 25' 16.68" E as numbers
            foreach ([...ExifPhotos::GPS_LATITUDE, ...ExifPhotos::GPS_LONGITUDE] as $number) {
                self::assertNotContains($number, array_filter($wire, is_int(...)), 'no GPS value among the numbers sent');
            }
        }

        self::assertSame([
            'bytes' => strlen(ExifPhotos::iphoneJpeg()),
            'format' => 'JPEG',
            'width' => 64,
            'height' => 48,
            'exif' => true,
            'cameraExif' => true,
            'dateTimeOriginal' => '2026:10:21 14:03:12',
            'offsetTimeOriginal' => '+02:00',
            // Raw on the wire, reduced to the family "phone" and the brand "Apple" by BrowserImageSummary
            'software' => '17.4.1',
            'make' => 'Apple',
            'orientation' => 6,
        ], $jpeg);
    }

    public function testTheBrowserReadsTheExifItemOfAHeic(): void
    {
        $heic = ExifPhotos::heic(4032, 3024, ExifPhotos::iphoneExif());
        // A portrait photo: stored landscape, turned a quarter by its irot - read as shown, like the server's libheif
        $inIdat = ExifPhotos::heic(1200, 900, ExifPhotos::nikonExif(), exifInIdat: true, quarterTurns: 3);
        $avif = ExifPhotos::heic(640, 480, ExifPhotos::screenshotExif(), majorBrand: 'avif');

        [$fromMdat, $fromIdat, $fromAvif] = $this->runInNode([$heic, $inIdat, $avif]);

        $metadata = BrowserImageSummary::fromInput($fromMdat);
        self::assertNotNull($metadata);
        self::assertSame([
            'version' => 1,
            'source' => 'browser',
            'bytes' => strlen($heic),
            'format' => 'HEIC',
            // The primary image's own ispe - not the larger one no item uses
            'width' => 4032,
            'height' => 3024,
            'exif' => true,
            'cameraExif' => true,
            'capturedAt' => '2026-10-21T14:03:12',
            'capturedAtOffset' => '+02:00',
            'software' => 'phone',
            'make' => 'Apple',
            'orientation' => 6,
        ], $metadata->toArray());

        $metadata = BrowserImageSummary::fromInput($fromIdat);
        self::assertNotNull($metadata);
        self::assertSame(['HEIC', 900, 1200, '2026-03-14T09:26:53', null, 'camera', 'Nikon'], [
            $metadata->format, $metadata->width, $metadata->height, $metadata->capturedAt, $metadata->capturedAtOffset, $metadata->software?->value, $metadata->make,
        ]);

        $metadata = BrowserImageSummary::fromInput($fromAvif);
        self::assertNotNull($metadata);
        self::assertSame(['AVIF', false, 'screenshot'], [$metadata->format, $metadata->cameraExif, $metadata->software?->value]);
    }

    public function testWhatItCannotReadIsNothingAndNeverAnError(): void
    {
        $jpeg = ExifPhotos::iphoneJpeg();
        $exifEnd = strpos($jpeg, ExifPhotos::MODEL);
        self::assertIsInt($exifEnd);

        $results = $this->runInNode([
            // Formats it does not read: the server's own reading of the shrunk file stands
            ExifPhotos::png(20, 20, ExifPhotos::iphoneExif()),
            ExifPhotos::webp(20, 20, ExifPhotos::iphoneExif()),
            'not an image at all',
            // Cut off in the middle of its EXIF, and before any frame header
            substr($jpeg, 0, $exifEnd),
            // A HEIC without its meta box
            substr(ExifPhotos::heic(10, 10, ExifPhotos::iphoneExif()), 0, 24),
            '',
        ]);

        self::assertSame([null, null, null, null, null, null], $results);
    }

    /**
     * @param list<string> $photos
     * @return list<mixed>
     */
    private function runInNode(array $photos): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/photo-metadata-harness.mjs']);
        $process->setInput((string) json_encode(array_map(base64_encode(...), $photos), JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }

    private function file(string $content): string
    {
        $path = ExifPhotos::file($content);
        $this->files[] = $path;

        return $path;
    }
}
