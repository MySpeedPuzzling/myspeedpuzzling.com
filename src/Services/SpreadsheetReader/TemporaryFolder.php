<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader;

use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;

/**
 * The folder one opened file may write into while it is read: <base>/msp-spreadsheet-<random>, readable by the
 * server's user only, made when something is first written (most files never need it) and removed when the file is
 * closed. Files in it get random names and are created exclusively - nothing that is already there is ever opened.
 *
 * A worker that dies leaves its folder behind: prune() removes the ones nobody touched for a while.
 */
final class TemporaryFolder
{
    private const string PREFIX = 'msp-spreadsheet-';

    private null|string $path = null;
    private int $touchedAt = 0;

    public function __construct(
        private readonly string $base,
        /** A folder that is in use says so once a minute: prune() goes by when it was last written to */
        private readonly int $keepAliveNanoseconds = 60_000_000_000,
    ) {
    }

    /**
     * A path for a new file in the folder; the caller creates it exclusively (fopen mode "x").
     */
    public function newFile(string $extension): string
    {
        return $this->path() . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    /**
     * To be called now and then while the file is read: a read that takes long is not taken for an abandoned one.
     */
    public function keepAlive(): void
    {
        if ($this->path !== null && hrtime(true) - $this->touchedAt >= $this->keepAliveNanoseconds) {
            @touch($this->path);
            $this->touchedAt = hrtime(true);
        }
    }

    /**
     * Never throws: what can not be removed now is left for prune().
     */
    public function remove(): void
    {
        if ($this->path !== null) {
            self::removeFolder($this->path);
            $this->path = null;
        }
    }

    /**
     * Removes the folders of readers that never closed (a killed worker), last written to before the given moment.
     *
     * @return int how many folders were removed
     */
    public static function prune(string $base, \DateTimeImmutable $before): int
    {
        $removed = 0;
        $folders = glob(rtrim($base, '/') . '/' . self::PREFIX . '*', GLOB_ONLYDIR);

        foreach ($folders === false ? [] : $folders as $folder) {
            if (preg_match('~/' . self::PREFIX . '[0-9a-f]{16}$~', $folder) !== 1 || is_link($folder)) {
                continue;
            }

            $modified = @filemtime($folder);

            if ($modified !== false && $modified < $before->getTimestamp()) {
                self::removeFolder($folder);
                $removed += is_dir($folder) ? 0 : 1;
            }
        }

        return $removed;
    }

    private function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        $base = rtrim($this->base, '/');

        if (is_dir($base) === false && @mkdir($base, 0700, true) === false && is_dir($base) === false) {
            throw new SpreadsheetReaderFailed(sprintf('The temporary folder "%s" can not be created.', $base));
        }

        $path = $base . '/' . self::PREFIX . bin2hex(random_bytes(8));

        if (@mkdir($path, 0700) === false) {
            throw new SpreadsheetReaderFailed(sprintf('A folder can not be created in the temporary folder "%s".', $base));
        }

        $this->touchedAt = hrtime(true);

        return $this->path = $path;
    }

    private static function removeFolder(string $folder): void
    {
        try {
            $entries = scandir($folder);

            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $folder . '/' . $entry;

                // A link is removed, never followed
                if (is_dir($path) && is_link($path) === false) {
                    self::removeFolder($path);
                } else {
                    @unlink($path);
                }
            }

            @rmdir($folder);
        } catch (\Throwable) {
            // Left for prune()
        }
    }
}
