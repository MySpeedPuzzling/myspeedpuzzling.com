<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Imagick;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\MediaType;
use Intervention\Image\Typography\FontFactory;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ImageMetadata;
use SpeedPuzzling\Web\Services\ResultImagePng;
use SpeedPuzzling\Web\Tests\ImagesWithMetadata;

final class ResultImagePngTest extends TestCase
{
    public function testOpaqueEncodingKeepsEveryPixel(): void
    {
        $card = $this->cardLikeImage();

        // How the card was encoded before: Intervention's PNG encoder always writes PNG32 (RGBA)
        $before = (string) $card->encodeUsingMediaType(MediaType::IMAGE_PNG, quality: 100);
        $native = $card->core()->native();
        assert($native instanceof Imagick);
        $after = ResultImagePng::encode($native);

        $beforePixels = new Imagick();
        $beforePixels->readImageBlob($before);
        $afterPixels = new Imagick();
        $afterPixels->readImageBlob($after);

        self::assertSame([800, 800], [$afterPixels->getImageWidth(), $afterPixels->getImageHeight()]);
        self::assertFalse($afterPixels->getImageAlphaChannel(), 'nothing to be transparent - no alpha channel stored');

        // Every channel of every pixel (alpha included: fully opaque before, opaque by definition after)
        [, $differentPixels] = $afterPixels->compareImages($beforePixels, Imagick::METRIC_ABSOLUTEERRORMETRIC);
        self::assertSame(0.0, (float) $differentPixels);

        self::assertLessThan(strlen($before), strlen($after));
    }

    public function testTheEncodedCardCarriesNoMetadata(): void
    {
        $card = $this->cardLikeImage(ImagesWithMetadata::jpeg(600, 400));
        $native = $card->core()->native();
        assert($native instanceof Imagick);
        ImageMetadata::strip($native);

        $png = ResultImagePng::encode($native);

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        self::assertSame([], array_values(array_intersect(
            ImagesWithMetadata::pngChunkTypes($png),
            ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'],
        )));
        self::assertStringNotContainsString(ImagesWithMetadata::CAMERA, $png);
    }

    public function testTheDrawnImageIsLeftAsItWas(): void
    {
        $card = $this->cardLikeImage();
        $native = $card->core()->native();
        assert($native instanceof Imagick);
        $before = $native->getImageSignature();

        ResultImagePng::encode($native);

        self::assertSame($before, $native->getImageSignature());
        self::assertTrue($native->getImageAlphaChannel());
    }

    /**
     * What GetResultImage draws: a photo, two translucent overlays and outlined text
     */
    private function cardLikeImage(null|string $photo = null): ImageInterface
    {
        $photo ??= (string) file_get_contents(__DIR__ . '/../fixtures/result-image/photo.jpg');

        return (new ImageManager(new Driver()))->decode($photo)
            ->cover(800, 800)
            ->drawRectangle(function (RectangleFactory $rectangle): void {
                $rectangle->at(0, 0);
                $rectangle->size(800, 800);
                $rectangle->background('rgba(250, 114, 111, 0.44)');
            })
            ->drawRectangle(function (RectangleFactory $rectangle): void {
                $rectangle->at(0, 0);
                $rectangle->size(800, 800);
                $rectangle->background('rgba(0, 0, 0, 0.55)');
            })
            ->text('00:36:40', 400, 300, function (FontFactory $font): void {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size(80);
                $font->align('center', 'top');
            });
    }
}
