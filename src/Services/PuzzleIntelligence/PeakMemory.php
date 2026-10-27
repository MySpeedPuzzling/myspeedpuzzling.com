<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

/**
 * The process's peak resident memory (VmHWM of /proc/self/status, MB) - what a container's memory limit kills by, higher
 * than PHP's memory_get_peak_usage(). Null where /proc is not there (macOS).
 */
final readonly class PeakMemory
{
    public static function residentMegabytes(): null|int
    {
        $status = @file_get_contents('/proc/self/status');

        if ($status === false || preg_match('/^VmHWM:\s+(\d+)\s+kB/m', $status, $match) !== 1) {
            return null;
        }

        return (int) round((int) $match[1] / 1024);
    }
}
