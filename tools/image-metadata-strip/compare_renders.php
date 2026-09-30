<?php

// imgproxy renderings before vs after the strip: dimensions + how different the pixels are
// (RMSE 0..1 over the whole image, and the largest single-pixel difference).
$dir = $argv[1];
foreach (glob("$dir/render-before/*") as $before) {
    $name = basename($before);
    $after = "$dir/render-after/$name";
    $a = new Imagick($before);
    $b = new Imagick($after);
    $sizeA = $a->getImageWidth() . 'x' . $a->getImageHeight();
    $sizeB = $b->getImageWidth() . 'x' . $b->getImageHeight();
    if ($sizeA !== $sizeB) {
        printf("%-16s %s -> %s  GEOMETRY CHANGED\n", $name, $sizeA, $sizeB);
        continue;
    }
    [, $rmse] = $a->compareImages($b, Imagick::METRIC_ROOTMEANSQUAREDERROR);
    [, $peak] = $a->compareImages($b, Imagick::METRIC_PEAKABSOLUTEERROR);
    $bytesSame = file_get_contents($before) === file_get_contents($after);
    printf("%-16s %s  bytes %s  rmse %.5f  peak %.4f\n", $name, $sizeA, $bytesSame ? 'same' : 'diff', $rmse, $peak);
}
