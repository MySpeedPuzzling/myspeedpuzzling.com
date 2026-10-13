<?php

/**
 * The evidence the simulated phones upload (README.md): synthetic pictures, no people, made here so no footage ever
 * enters the repository. Writes into the given directory (input/ is gitignored):
 * - photo.jpg   a 1280×720 JPEG of ~150 KB (TARGET_PHOTO_BYTES) - every photo kind (periodic, start, button, end,
 *               empty mat); the phones' periodic photos are smaller (640×360, ~35 KB), so this is the pessimistic size
 * - burst.bin   a finish burst: BURST_FRAMES 640×360 JPEG frames back to back (±5 s at 10 fps, ~1.2 MB), the way
 *               assets/online/frame_buffer.js builds one
 * - burst.json  its manifest without the capture times: [{offset, length}] - the load script adds captureMs per frame
 *
 * Usage: php tools/online-load/make-media.php tools/online-load/input
 * Needs GD with JPEG (the site's PHP image has it).
 */

declare(strict_types=1);

const TARGET_PHOTO_BYTES = 150_000;
const BURST_FRAMES = 100;
// One noise pixel per this many pixels - tuned so the sizes come out like a phone's
const PHOTO_NOISE_EVERY = 40;
const FRAME_NOISE_EVERY = 2000;

$directory = rtrim($argv[1] ?? '', '/');

if ($directory === '' || !is_dir($directory)) {
    fwrite(STDERR, "usage: php make-media.php <existing output directory>\n");
    exit(2);
}

mt_srand(20261013);

/**
 * A table seen from above: a mat, a puzzle of coloured pieces, some noise - enough detail that JPEG has work to do
 */
function picture(int $width, int $height, int $variant, int $noiseEvery): GdImage
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 34, 78, 52));

    $cols = 24;
    $rows = 16;
    $pieceWidth = intdiv((int) ($width * 0.7), $cols);
    $pieceHeight = intdiv((int) ($height * 0.7), $rows);
    $left = intdiv($width - $pieceWidth * $cols, 2);
    $top = intdiv($height - $pieceHeight * $rows, 2);

    for ($row = 0; $row < $rows; $row++) {
        for ($col = 0; $col < $cols; $col++) {
            // The puzzle fills up frame by frame in a burst
            if (($row * $cols + $col) % 97 < $variant % 5) {
                continue;
            }

            $color = imagecolorallocate($image, mt_rand(40, 230), mt_rand(40, 230), mt_rand(40, 230));
            $x = $left + $col * $pieceWidth;
            $y = $top + $row * $pieceHeight;
            imagefilledrectangle($image, $x, $y, $x + $pieceWidth - 2, $y + $pieceHeight - 2, $color);
        }
    }

    // Sensor noise
    for ($i = 0; $i < intdiv($width * $height, $noiseEvery); $i++) {
        $shade = mt_rand(0, 255);
        imagesetpixel($image, mt_rand(0, $width - 1), mt_rand(0, $height - 1), imagecolorallocate($image, $shade, $shade, $shade));
    }

    return $image;
}

function jpeg(GdImage $image, int $quality): string
{
    ob_start();
    imagejpeg($image, null, $quality);

    return (string) ob_get_clean();
}

// The photo: the quality whose size comes closest to the target
$photo = picture(1280, 720, 0, PHOTO_NOISE_EVERY);
$best = '';

foreach ([50, 60, 70, 75, 80, 85, 90, 95] as $quality) {
    $bytes = jpeg($photo, $quality);

    if ($best === '' || abs(strlen($bytes) - TARGET_PHOTO_BYTES) < abs(strlen($best) - TARGET_PHOTO_BYTES)) {
        $best = $bytes;
    }
}

file_put_contents($directory . '/photo.jpg', $best);

$burst = '';
$manifest = [];

for ($frame = 0; $frame < BURST_FRAMES; $frame++) {
    $bytes = jpeg(picture(640, 360, $frame, FRAME_NOISE_EVERY), 30);
    $manifest[] = ['offset' => strlen($burst), 'length' => strlen($bytes)];
    $burst .= $bytes;
}

file_put_contents($directory . '/burst.bin', $burst);
file_put_contents($directory . '/burst.json', json_encode($manifest, JSON_THROW_ON_ERROR));

printf("photo.jpg %d bytes, burst.bin %d bytes (%d frames)\n", strlen($best), strlen($burst), BURST_FRAMES);
