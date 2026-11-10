<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Imagick;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ResultImageText;

final class ResultImageTextTest extends TestCase
{
    private const string FONT = __DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf';
    private const int SIZE = 800;
    private const int FONT_SIZE = 57;

    public function testALongNameWrapsExactlyAsTheLibraryWouldLayItOut(): void
    {
        $text = 'Foul Play & Cabernet - A Mystery Jigsaw Thriller with a Secret Puzzle Image';

        $drawn = $this->drawn(fn (ImageInterface $image) => ResultImageText::draw($image, $text, 400, 100, $this->font(...)));
        $library = $this->drawn(fn (ImageInterface $image) => $image->text($text, 400, 100, function (FontFactory $font): void {
            $this->font($font);
            $font->color('#ffffff');
        }));

        // The white of the letters is where it was: same lines, same line breaks, same places
        self::assertSame($this->inkRows($library), $this->inkRows($drawn));
        self::assertGreaterThanOrEqual(3, $this->rowBlocks($drawn));
    }

    public function testTheLettersAreOutlined(): void
    {
        $canvas = (new ImageManager(new Driver()))->createImage(self::SIZE, self::SIZE)->fill('#808080');

        ResultImageText::draw($canvas, 'Harbour', 400, 100, $this->font(...));

        $native = $canvas->core()->native();
        assert($native instanceof Imagick);
        $range = $native->getImageChannelRange(Imagick::CHANNEL_RED);

        self::assertEqualsWithDelta(0.0, (float) $range['minima'], 2000.0, 'the outline is black');
        self::assertEqualsWithDelta((float) Imagick::getQuantum(), (float) $range['maxima'], 2000.0, 'the letters are white');
    }

    public function testAnEmptyLineDrawsNothing(): void
    {
        $canvas = (new ImageManager(new Driver()))->createImage(100, 100)->fill('#808080');
        $native = $canvas->core()->native();
        assert($native instanceof Imagick);
        $before = $native->getImageSignature();

        ResultImageText::draw($canvas, '', 50, 10, $this->font(...));

        self::assertSame($before, $native->getImageSignature());
    }

    private function font(FontFactory $font): void
    {
        $font->wrap((int) (self::SIZE * 0.97));
        $font->lineHeight(1.4);
        $font->filename(self::FONT);
        $font->size(self::FONT_SIZE);
        $font->align('center', 'top');
    }

    private function drawn(callable $draw): Imagick
    {
        $canvas = (new ImageManager(new Driver()))->createImage(self::SIZE, self::SIZE)->fill('#000000');
        $draw($canvas);
        $native = $canvas->core()->native();
        assert($native instanceof Imagick);

        return $native;
    }

    /**
     * For every pixel row whether it holds white letters
     *
     * @return list<bool>
     */
    private function inkRows(Imagick $image): array
    {
        $pixels = $image->exportImagePixels(0, 0, self::SIZE, self::SIZE, 'R', Imagick::PIXEL_CHAR);
        $rows = [];

        foreach (array_chunk($pixels, self::SIZE) as $row) {
            $rows[] = max($row) > 200;
        }

        return $rows;
    }

    private function rowBlocks(Imagick $image): int
    {
        $blocks = 0;
        $inBlock = false;

        foreach ($this->inkRows($image) as $hasInk) {
            if ($hasInk && !$inBlock) {
                $blocks++;
            }

            $inBlock = $hasInk;
        }

        return $blocks;
    }
}
