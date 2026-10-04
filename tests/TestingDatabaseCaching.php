<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Component\Finder\Finder;

final class TestingDatabaseCaching
{
    private const CACHE_VALID_FOR_SECONDS = 24 * 60 * 60;  // 24 hours

    /**
     * Every PHP file in the directories, plus the files given directly.
     */
    public static function calculateDirectoriesHash(string ...$paths): string
    {
        $directories = array_filter($paths, is_dir(...));
        $givenFiles = array_values(array_filter($paths, is_file(...)));

        $finder = new Finder();
        $finder = $finder->in($directories)->name('*.php')->files();
        $files = [...array_keys(iterator_to_array($finder->getIterator())), ...$givenFiles];
        $hash = '';

        foreach ($files as $file) {
            $hash .= md5_file($file);
        }

        return $hash;
    }

    public static function isCacheUpToDate(string $cacheFilePath, string $currentDatabaseHash): bool
    {
        if (!file_exists($cacheFilePath)) {
            return false;
        }

        $cachedDatabaseHash = file_get_contents($cacheFilePath);
        $lastModificationTimestamp = filemtime($cacheFilePath);
        $cacheValidUntil = time() + self::CACHE_VALID_FOR_SECONDS;

        if ($cacheValidUntil < $lastModificationTimestamp) {
            return false;
        }

        return $currentDatabaseHash === $cachedDatabaseHash;
    }
}
