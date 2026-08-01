<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped record of uploads that fell back to the local spool, so the
 * response can tell the user their file will be uploaded later. ResetInterface
 * is required: FrankenPHP worker mode reuses the service between requests.
 */
final class UploadFailureCollector implements ResetInterface
{
    /** @var list<string> */
    private array $failedPaths = [];

    public function recordFailure(string $path): void
    {
        if (!in_array($path, $this->failedPaths, true)) {
            $this->failedPaths[] = $path;
        }
    }

    public function hasFailures(): bool
    {
        return $this->failedPaths !== [];
    }

    /** @return list<string> */
    public function getFailedPaths(): array
    {
        return $this->failedPaths;
    }

    public function reset(): void
    {
        $this->failedPaths = [];
    }
}
