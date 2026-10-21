<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Screenshots sent with the feedback form (docs/features/feedback-screenshots.md). They travel as e-mail attachments,
 * re-encoded to a JPEG of at most MAX_DIMENSION px - a few hundred kB each, so a feedback mail stays a few MB at most.
 */
final class FeedbackScreenshotLimits
{
    public const int MAX_SCREENSHOTS = 3;

    // The same size the browser shrinks every photo to (assets/image_compression.js) - a phone screenshot's text stays readable
    public const int MAX_DIMENSION = 2000;

    public const int JPEG_QUALITY = 85;
}
