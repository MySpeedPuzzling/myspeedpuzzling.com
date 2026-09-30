<?php

// Pixel check of the pilot: decodes the backed-up original and the stripped file the origin serves
// now, the way a browser shows them (EXIF Orientation applied; HEIF gets its irot/imir from libheif),
// and compares dimensions + the SHA-256 of the decoded pixels.
// Run in the app image (PHP + Imagick): php decode_check.php /w/pilot/run/pairs.txt

$ok = true;
foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$before, $after] = explode('|', $line);
    $results = [];
    foreach ([$before, $after] as $path) {
        $image = new Imagick($path);
        $format = $image->getImageFormat();
        $orientation = $image->getImageOrientation();
        // HEIF: libheif already applied the container rotation; its EXIF orientation is informational
        if (!in_array($format, ['HEIC', 'HEIF', 'AVIF'], true)) {
            $image->autoOrient();
        }
        $results[] = [
            'format' => $format,
            'orientation' => $orientation,
            'size' => $image->getImageWidth() . 'x' . $image->getImageHeight(),
            'signature' => $image->getImageSignature(),
        ];
        $image->clear();
    }
    $same = $results[0]['size'] === $results[1]['size'] && $results[0]['signature'] === $results[1]['signature'];
    $ok = $ok && $same;
    printf(
        "%s %-5s %s -> %s  shown %s -> %s  pixels %s  %s\n",
        $same ? 'OK ' : 'BAD',
        $results[0]['format'],
        $results[0]['orientation'],
        $results[1]['orientation'],
        $results[0]['size'],
        $results[1]['size'],
        $results[0]['signature'] === $results[1]['signature'] ? 'identical' : 'DIFFERENT',
        basename($after),
    );
}
echo $ok ? "ALL PIXELS IDENTICAL\n" : "PIXEL DIFFERENCES\n";
