<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

readonly final class UploaderHelper
{
    public function __construct(
        private string $nginxProxyBaseUrl,
    ) {
    }

    public function getPublicPath(string $path): string
    {
        return $this->nginxProxyBaseUrl . '/original/' . ltrim($path, '/');
    }
}
