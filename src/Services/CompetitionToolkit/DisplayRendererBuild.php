<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which build of the display renderer this server hands out (D-displays-overlays.md §7.4): a short hash of the
 * `display` entry's files in Encore's entrypoints.json. A display that was opened before a deploy sees a different
 * value in its state and reloads itself at a calm moment (assets/displays/runtime.js). Null when the build has no
 * `display` entry (an old build, tests without assets) - then nothing reloads. Read per call: no state kept between
 * requests (worker mode), and the file is small.
 */
final readonly class DisplayRendererBuild
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/build/entrypoints.json')]
        private string $entrypointsPath,
    ) {
    }

    public function current(): null|string
    {
        $json = @file_get_contents($this->entrypointsPath);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !is_array($decoded['entrypoints'] ?? null) || !isset($decoded['entrypoints']['display'])) {
            return null;
        }

        return substr(sha1((string) json_encode($decoded['entrypoints']['display'])), 0, 12);
    }
}
