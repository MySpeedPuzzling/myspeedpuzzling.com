<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Component\Asset\Context\RequestStackContext;

class UploaderHelper
{
    public function __construct(
        private string $uploadedAssetsBaseUrl,
    ) {
    }


    public function getPublicPath(string $path): string
    {
        return $this->uploadedAssetsBaseUrl . '/' . $path;
    }
}
