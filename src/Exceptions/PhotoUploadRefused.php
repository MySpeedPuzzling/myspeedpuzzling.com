<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A direct photo upload that is not started (DirectPhotoUpload): the browser sends the photo with the form instead,
 * where the form's own validation tells the player what is wrong with it.
 */
final class PhotoUploadRefused extends \RuntimeException
{
    public function __construct(
        readonly public string $reason,
    ) {
        parent::__construct(sprintf('Direct photo upload refused: %s', $reason));
    }
}
