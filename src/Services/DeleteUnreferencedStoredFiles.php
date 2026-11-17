<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetStoredFileReferences;

/**
 * Deletes stored files that nothing in the database points at any more - the shared body of the after-commit clean-ups
 * of uploaded pictures (page sections, competition displays). A path some row still references stays: the change did
 * not commit after all, or another section / display (a duplicate shares its source's files) still shows it.
 *
 * A storage problem never fails the caller: the change is committed already, a stray object is only logged.
 */
readonly final class DeleteUnreferencedStoredFiles
{
    public function __construct(
        private Filesystem $filesystem,
        private GetStoredFileReferences $getStoredFileReferences,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $paths
     * @return list<string> the paths deleted
     */
    public function __invoke(array $paths): array
    {
        $paths = array_values(array_unique($paths));

        if ($paths === []) {
            return [];
        }

        $referenced = $this->getStoredFileReferences->referencedAmong($paths);
        $deleted = [];

        foreach ($paths as $path) {
            if (isset($referenced[$path])) {
                continue;
            }

            try {
                $this->filesystem->delete($path);
                $deleted[] = $path;
            } catch (FilesystemException $exception) {
                $this->logger->warning('A stored picture no longer referenced could not be deleted', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
            }
        }

        return $deleted;
    }
}
