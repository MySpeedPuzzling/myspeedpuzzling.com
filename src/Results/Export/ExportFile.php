<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

readonly final class ExportFile
{
    public function __construct(
        public string $content,
        public string $contentType,
        public string $fileExtension,
    ) {
    }
}
