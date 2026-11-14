<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactoryInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyInterface;

/**
 * Hands OpenSpout our own bounded store for a workbook's shared strings, whatever the workbook says about how many
 * it has - and keeps hold of it, so the workbook can look a string up while it reads its rows.
 */
final class SharedStringsStoreFactory implements CachingStrategyFactoryInterface
{
    /** The store of the workbook OpenSpout opened with this factory; null while it has no shared strings */
    public null|SharedStringsStore $store = null;

    // @phpstan-ignore return.internalInterface, return.internalInterface (the signature OpenSpout's public factory interface dictates)
    public function createBestCachingStrategy(null|int $sharedStringsUniqueCount, string $tempFolder): CachingStrategyInterface
    {
        return $this->store = new SharedStringsStore($tempFolder);
    }
}
