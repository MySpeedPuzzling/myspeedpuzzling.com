<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\SandboxCatalogueFileInvalid;
use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\ImportSandboxCatalogue;
use SpeedPuzzling\Web\Services\Sandbox\SandboxCatalogue;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ImportSandboxCatalogueHandler
{
    public function __construct(
        private SandboxMode $sandboxMode,
        private SandboxCatalogue $catalogue,
    ) {
    }

    /**
     * @return array<string, int> table => rows read from the file
     *
     * @throws SandboxModeIsOff
     * @throws SandboxCatalogueFileInvalid
     */
    public function __invoke(ImportSandboxCatalogue $message): array
    {
        $this->sandboxMode->assertOn();

        // The box keeps the export gzipped (lily.srv apps/msp-sandbox/README.md) - read it as it is
        $path = str_ends_with($message->path, '.gz') ? 'compress.zlib://' . $message->path : $message->path;
        $file = @fopen($path, 'rb');

        if ($file === false) {
            throw new SandboxCatalogueFileInvalid(sprintf('Cannot read "%s".', $message->path));
        }

        try {
            return $this->catalogue->import($this->lines($file));
        } finally {
            fclose($file);
        }
    }

    /**
     * @param resource $file
     * @return \Generator<int, string>
     */
    private function lines($file): \Generator
    {
        while (($line = fgets($file)) !== false) {
            yield $line;
        }
    }
}
