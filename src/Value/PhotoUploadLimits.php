<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How large an uploaded puzzle photo may be. The browser shrinks photos to 2000 px before it sends them
 * (assets/image_compression.js), but a phone browser cannot always do that - a 200 MP shot or a HEIC
 * outside Safari arrives as it was taken (25-55 MB). Such a photo is accepted and ImageOptimizer
 * scales it down on the server; the browser never sends a photo above MAX_PHOTO_BYTES, so a request
 * stays below PHP's post_max_size (128M in web-base-php85/php.ini) - above it PHP drops the whole
 * request and the player loses everything typed into the form.
 */
final class PhotoUploadLimits
{
    public const int MAX_PHOTO_BYTES = 60_000_000;

    /** What the browser may send in one form submit - post_max_size with room for the other fields */
    public const int MAX_REQUEST_BYTES = 125_000_000;

    public const array MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/avif',
    ];
}
