<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ResultImageJpeg;

final class ResultImageJpegTest extends TestCase
{
    public function testTheCardIsAProgressiveSrgbJpeg(): void
    {
        $jpeg = ResultImageJpeg::encode($this->photoLikeImage());
        $encoded = new Imagick();
        $encoded->readImageBlob($jpeg);

        self::assertSame('JPEG', $encoded->getImageFormat());
        self::assertSame(Imagick::COLORSPACE_SRGB, $encoded->getImageColorspace());
        self::assertStringContainsString("\xFF\xC2", $jpeg, 'a progressive frame header: the picture appears at once and sharpens');
        self::assertSame([64, 64], [$encoded->getImageWidth(), $encoded->getImageHeight()]);
    }

    public function testAPrintFileInCmykStillGivesAnSrgbCard(): void
    {
        $cmyk = $this->photoLikeImage();
        $cmyk->transformImageColorspace(Imagick::COLORSPACE_CMYK);
        self::assertSame(Imagick::COLORSPACE_CMYK, $cmyk->getImageColorspace());

        $encoded = new Imagick();
        $encoded->readImageBlob(ResultImageJpeg::encode($cmyk));

        // A CMYK JPEG shows inverted or washed out in browsers and social apps
        self::assertSame(Imagick::COLORSPACE_SRGB, $encoded->getImageColorspace());
    }

    public function testTransparencyIsFlattened(): void
    {
        $transparent = new Imagick();
        $transparent->newImage(32, 32, new ImagickPixel('transparent'), 'png');

        $encoded = new Imagick();
        $encoded->readImageBlob(ResultImageJpeg::encode($transparent));

        self::assertSame(0, (int) $encoded->getImageAlphaChannel());
    }

    public function testTheImageGivenStaysAsItIs(): void
    {
        $image = $this->photoLikeImage();
        $image->setImageFormat('png');

        ResultImageJpeg::encode($image);

        self::assertSame('png', strtolower($image->getImageFormat()));
    }

    private function photoLikeImage(): Imagick
    {
        $image = new Imagick();
        $image->newPseudoImage(64, 64, 'gradient:#d63c42-#2b3445');
        $image->setImageFormat('png');

        return $image;
    }
}
