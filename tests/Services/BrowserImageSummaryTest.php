<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\BrowserImageSummary;
use SpeedPuzzling\Web\Value\ImageSoftwareFamily;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;
use SpeedPuzzling\Web\Value\UploadedImageMetadataSource;

/**
 * docs/features/upload-image-metadata.md "Read in the browser" - what the page says about a photo it shrank is checked
 * strictly (allow-listed keys only, types, lengths, ranges), reduced like the server's own reading, and stored only when
 * the file that arrived has no EXIF of its own.
 */
final class BrowserImageSummaryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function phonePhoto(): array
    {
        return [
            'bytes' => 3_456_789,
            'format' => 'JPEG',
            'width' => 4032,
            'height' => 3024,
            'exif' => true,
            'cameraExif' => true,
            'dateTimeOriginal' => '2026:10:21 14:03:12',
            'offsetTimeOriginal' => '+02:00',
            'software' => '18.0.1',
            'make' => 'Apple',
            'orientation' => 6,
        ];
    }

    public function testAPhonePhotoIsReducedLikeTheServersOwnReading(): void
    {
        $metadata = BrowserImageSummary::fromInput(self::phonePhoto());

        self::assertNotNull($metadata);
        self::assertSame([
            'version' => UploadedImageMetadata::VERSION,
            'source' => 'browser',
            'bytes' => 3_456_789,
            'format' => 'JPEG',
            'width' => 4032,
            'height' => 3024,
            'exif' => true,
            'cameraExif' => true,
            'capturedAt' => '2026-10-21T14:03:12',
            'capturedAtOffset' => '+02:00',
            // The raw Software and Make strings stop here
            'software' => 'phone',
            'make' => 'Apple',
            'orientation' => 6,
        ], $metadata->toArray());
    }

    public function testUnknownKeysAreDroppedAndOnlyTheReducedValuesAreKept(): void
    {
        $metadata = BrowserImageSummary::fromInput([
            ...self::phonePhoto(),
            'gpsLatitude' => 50.0875,
            'serialNumber' => 'SN-4815162342',
            'model' => 'iPhone 15 Pro',
            'software' => 'Adobe Photoshop 25.0 (C:\\Users\\alex.example\\export)',
            'make' => 'Some Unknown Brand Ltd.',
        ]);

        self::assertNotNull($metadata);
        $stored = (string) json_encode($metadata->toArray());

        foreach (['gps', '50.0875', 'SN-', 'iPhone', 'alex', 'Photoshop', 'Unknown Brand'] as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $stored);
        }

        self::assertSame(ImageSoftwareFamily::Editor, $metadata->software);
        self::assertSame('Other', $metadata->make);
    }

    public function testAScreenshotLabelAndAClockNeverSet(): void
    {
        $metadata = BrowserImageSummary::fromInput([
            'bytes' => 812_000,
            'format' => 'HEIC',
            'width' => 1179,
            'height' => 2556,
            'exif' => true,
            'cameraExif' => false,
            'dateTimeOriginal' => '0000:00:00 00:00:00',
            'screenshot' => true,
        ]);

        self::assertNotNull($metadata);
        self::assertSame(ImageSoftwareFamily::Screenshot, $metadata->software);
        self::assertNull($metadata->capturedAt);
        self::assertFalse($metadata->cameraExif);
    }

    public function testAPhotoWithoutExif(): void
    {
        $metadata = BrowserImageSummary::fromJson('{"bytes":600000,"format":"JPEG","width":1600,"height":1200,"exif":false,"cameraExif":false}');

        self::assertNotNull($metadata);
        self::assertFalse($metadata->exif);
        self::assertSame(UploadedImageMetadataSource::Browser, $metadata->source);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideRefused(): iterable
    {
        $phone = self::phonePhoto();

        yield 'not an object' => [['JPEG', 1, 2]];
        yield 'a string' => ['JPEG'];
        yield 'missing bytes' => [array_diff_key($phone, ['bytes' => true])];
        yield 'bytes as a string' => [['bytes' => '3456789'] + $phone];
        yield 'zero bytes' => [['bytes' => 0] + $phone];
        yield 'a format it never sends' => [['format' => 'PNG'] + $phone];
        yield 'a lower-case format' => [['format' => 'jpeg'] + $phone];
        yield 'a width out of range' => [['width' => 1_000_000] + $phone];
        yield 'a negative height' => [['height' => -3] + $phone];
        yield 'exif as a number' => [['exif' => 1] + $phone];
        yield 'cameraExif missing' => [array_diff_key($phone, ['cameraExif' => true])];
        yield 'an orientation of 9' => [['orientation' => 9] + $phone];
        yield 'an orientation as a string' => [['orientation' => '6'] + $phone];
        yield 'a too long software' => [['software' => str_repeat('a', 129)] + $phone];
        yield 'a too long capture time' => [['dateTimeOriginal' => str_repeat('2026:10:21 ', 4)] + $phone];
        yield 'a control character' => [['make' => "Apple\nInjected"] + $phone];
        yield 'an empty make' => [['make' => ''] + $phone];
        yield 'an untrimmed make' => [['make' => ' Apple'] + $phone];
        yield 'a make as an array' => [['make' => ['Apple']] + $phone];
        yield 'screenshot as a string' => [['screenshot' => 'yes'] + $phone];
        yield 'EXIF values without EXIF' => [['exif' => false, 'cameraExif' => false] + $phone];
        yield 'camera EXIF without EXIF' => [['bytes' => 1, 'format' => 'JPEG', 'width' => 1, 'height' => 1, 'exif' => false, 'cameraExif' => true]];
    }

    #[DataProvider('provideRefused')]
    public function testAnythingOffTheRulesIsRefusedWhole(mixed $input): void
    {
        self::assertNull(BrowserImageSummary::fromInput($input));
    }

    public function testBrokenOrOversizedJsonIsNothing(): void
    {
        self::assertNull(BrowserImageSummary::fromJson(null));
        self::assertNull(BrowserImageSummary::fromJson(''));
        self::assertNull(BrowserImageSummary::fromJson('{"bytes":'));
        self::assertNull(BrowserImageSummary::fromJson('{"bytes":1,"format":"JPEG","width":1,"height":1,"exif":false,"cameraExif":false,"nested":{"a":1}}'));
        self::assertNull(BrowserImageSummary::fromJson((string) json_encode(self::phonePhoto() + ['padding' => str_repeat('x', BrowserImageSummary::MAX_JSON_BYTES)])));
        self::assertNotNull(BrowserImageSummary::fromJson((string) json_encode(self::phonePhoto())));
    }

    public function testTheServersOwnExifWinsElseTheBrowsersReadingIsStored(): void
    {
        $browser = BrowserImageSummary::fromInput(self::phonePhoto());
        self::assertNotNull($browser);

        $serverWithExif = new UploadedImageMetadata(bytes: 900_000, format: 'JPEG', width: 2000, height: 1500, exif: true, cameraExif: true, capturedAt: '2026-10-20T08:00:00');
        $serverWithoutExif = new UploadedImageMetadata(bytes: 512_000, format: 'JPEG', width: 2000, height: 1500, exif: false, cameraExif: false);

        // EXIF in the file that arrived (a photo sent as picked): the browser's word changes nothing
        self::assertSame($serverWithExif, UploadedImageMetadata::preferring($serverWithExif, $browser));
        // The browser shrank it: the file has no EXIF left, what the browser read before is kept
        self::assertSame($browser, UploadedImageMetadata::preferring($serverWithoutExif, $browser));
        // The server could not read the file at all
        self::assertSame($browser, UploadedImageMetadata::preferring(null, $browser));
        // Nothing from the browser: the server's reading, as before
        self::assertSame($serverWithoutExif, UploadedImageMetadata::preferring($serverWithoutExif, null));
        self::assertNull(UploadedImageMetadata::preferring(null, null));
    }

    public function testTheSourceSurvivesStorageAndOlderSummariesAreTheServers(): void
    {
        $browser = BrowserImageSummary::fromInput(self::phonePhoto());
        self::assertNotNull($browser);

        self::assertEquals($browser, UploadedImageMetadata::fromArray($browser->toArray()));

        $stored = $browser->toArray();
        unset($stored['source']);
        self::assertSame(UploadedImageMetadataSource::Server, UploadedImageMetadata::fromArray($stored)?->source);
    }
}
