<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A Google sheet's export as it arrived in a local file (Services\DataImport\GoogleSheetFile).
 */
readonly final class FetchedGoogleSheet
{
    public function __construct(
        public int $bytes,
        /** The document's title with `.xlsx`, as Google names the download - null when it gave none */
        public null|string $fileName,
    ) {
    }
}
