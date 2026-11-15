<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The safety net's word for an import whose pairing did not come to rest (docs/features/data-import/pairing.md "When
 * a run fails"): counted on the import and BuildImportPuzzles queued again - a bounded number of times. Not routed:
 * handled at once, in its own transaction. Answers whether it was queued.
 */
readonly final class RequestImportPairing
{
    public function __construct(
        public string $importId,
    ) {
    }
}
