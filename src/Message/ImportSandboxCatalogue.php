<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Loads a catalogue file written by myspeedpuzzling:sandbox:export-catalogue into the sandbox's database
 * (docs/features/sandbox/README.md "Data"). Sandbox only. One transaction: a broken file changes nothing.
 */
readonly final class ImportSandboxCatalogue
{
    public function __construct(
        public string $path,
    ) {
    }
}
