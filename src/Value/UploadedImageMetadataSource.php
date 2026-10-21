<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Who read an UploadedImageMetadata (docs/features/upload-image-metadata.md "Read in the browser"). EXIF is
 * client-controlled either way - but a browser summary is what the page said about the photo, not what reached us.
 */
enum UploadedImageMetadataSource: string
{
    // UploadedImageSummariser on the file that arrived
    case Server = 'server';

    // assets/photo_metadata.js on the photo as picked, before the browser shrank it (a canvas re-encode drops all
    // EXIF) - validated by BrowserImageSummary
    case Browser = 'browser';
}
