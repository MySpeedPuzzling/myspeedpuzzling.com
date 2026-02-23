<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

readonly final class UploaderHelper
{
    public function __construct(
        private string $uploadedAssetsBaseUrl,
        private string $imageProvider,
        private string $nginxProxyBaseUrl,
    ) {
    }

    public function getPublicPath(string $path): string
    {
        if ($this->imageProvider === 'imgproxy') {
            return $this->nginxProxyBaseUrl . '/original/' . ltrim($path, '/');
        }

        return $this->uploadedAssetsBaseUrl . '/' . $path;
    }
}
