<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FeedbackFormData
{
    // Null when the page was opened without ?url= (a plain link to the feedback page)
    public null|string $url = null;
    public string $message = '';

    /**
     * Screenshots that could not be uploaded in the background and came with the form as files
     *
     * @var array<UploadedFile>
     */
    public array $screenshots = [];
}
