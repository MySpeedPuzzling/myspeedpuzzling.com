<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A made-up box picture for a demo event's new secret puzzles (spws-demo-ux-leftovers.md §9): an unboxed challenge
 * must show "no picture" before its results are published and a picture after - a puzzle without any picture never
 * shows the difference. Drawn with GD, never a real box or a real event's artwork: coloured pieces from the name's
 * hash and "DEMO BOX" with the name on a white band. Deterministic - the same name draws the same picture.
 */
final readonly class SandboxPlaceholderBox
{
    private const int WIDTH = 300;
    private const int HEIGHT = 225;
    private const int SCALE = 4;

    /**
     * A PNG in the temp dir, as an upload the handlers may optimise and move (like a logo the organiser picks)
     */
    public static function upload(string $name): UploadedFile
    {
        $small = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $hash = md5($name);

        // A grid of "pieces" in the name's colours
        $columns = 8;
        $rows = 6;
        $pieceWidth = intdiv(self::WIDTH, $columns);
        $pieceHeight = intdiv(self::HEIGHT, $rows);

        for ($row = 0; $row < $rows; $row++) {
            for ($column = 0; $column < $columns; $column++) {
                $seed = hexdec(substr(md5($hash . $row . ':' . $column), 0, 6));
                $color = imagecolorallocate($small, 40 + ($seed & 0x7F), 40 + (($seed >> 8) & 0x7F), 90 + (($seed >> 16) & 0x7F));
                assert($color !== false);
                imagefilledrectangle($small, $column * $pieceWidth, $row * $pieceHeight, ($column + 1) * $pieceWidth - 2, ($row + 1) * $pieceHeight - 2, $color);
            }
        }

        $white = imagecolorallocate($small, 255, 255, 255);
        $ink = imagecolorallocate($small, 10, 26, 107);
        assert($white !== false && $ink !== false);
        imagefilledrectangle($small, 0, 80, self::WIDTH, 145, $white);
        imagestring($small, 5, 12, 88, 'DEMO BOX', $ink);
        imagestring($small, 3, 12, 112, mb_strimwidth($name, 0, 46, '...'), $ink);

        $big = imagescale($small, self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE, IMG_NEAREST_NEIGHBOUR);
        assert($big !== false);

        $path = sys_get_temp_dir() . '/msp-sandbox-box-' . bin2hex(random_bytes(6)) . '.png';
        imagepng($big, $path);

        return new UploadedFile($path, 'demo-box.png', 'image/png', null, test: true);
    }
}
