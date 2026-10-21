<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What wrote an uploaded image, as far as its EXIF `Software` tag (and an iPhone/Mac screenshot's own label) tells -
 * the only thing kept of that tag (docs/features/upload-image-metadata.md). The tag itself is never stored: it is free
 * text, and some tools write a user name or a file path into it.
 */
enum ImageSoftwareFamily: string
{
    // A camera's own firmware ("Ver.1.10", "ILCE-7M3 v3.01") - the make is a camera brand
    case Camera = 'camera';

    // A phone's operating system or camera app ("17.4.1" on an iPhone, "G998BXXU5CVDD" on a Samsung)
    case Phone = 'phone';

    // An image editor or converter (Photoshop, Lightroom, GIMP, Snapseed, ImageMagick, ...)
    case Editor = 'editor';

    // A screenshot (iOS/macOS label the EXIF user comment "Screenshot"; screenshot tools name themselves)
    case Screenshot = 'screenshot';

    // A Software tag none of the above recognises
    case Unknown = 'unknown';
}
