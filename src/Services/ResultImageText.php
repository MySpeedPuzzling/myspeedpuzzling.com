<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Imagick;
use ImagickPixel;
use Intervention\Image\Drivers\Imagick\FontProcessor;
use Intervention\Image\Geometry\Point;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use Intervention\Image\Typography\Line;

/**
 * White text with a black outline on the result share card. The lines, their breaks and their places come from
 * Intervention's own layout; only the outline is drawn differently. Intervention plots nine shifted copies of every
 * line under the text, a third of the time of drawing a card - here it is one native stroke, which looks the same.
 */
final class ResultImageText
{
    private const string TEXT_COLOR = '#ffffff';
    private const string OUTLINE_COLOR = '#000000';

    /**
     * ImageMagick centres a stroke on the edge of the letter: 2 px wide = 1 px outside of it
     */
    private const float OUTLINE_WIDTH = 2.0;

    /**
     * @param callable(FontFactory): mixed $font
     */
    public static function draw(ImageInterface $image, string $text, int $x, int $y, callable $font): void
    {
        if ($text === '') {
            return;
        }

        $processor = $image->driver()->fontProcessor();
        assert($processor instanceof FontProcessor);
        $native = $image->core()->native();
        assert($native instanceof Imagick);

        $typeface = FontFactory::build($font);
        $textColor = new ImagickPixel(self::TEXT_COLOR);

        $outline = $processor->toImagickDraw($typeface, $textColor);
        $outline->setStrokeColor(new ImagickPixel(self::OUTLINE_COLOR));
        $outline->setStrokeWidth(self::OUTLINE_WIDTH);
        $letters = $processor->toImagickDraw($typeface, $textColor);

        /** @var Line $line */
        foreach ($processor->textBlock($text, $typeface, new Point($x, $y)) as $line) {
            $position = $line->position();
            $native->annotateImage($outline, $position->x(), $position->y(), 0, (string) $line);
            $native->annotateImage($letters, $position->x(), $position->y(), 0, (string) $line);
        }
    }
}
