<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Psr\Clock\ClockInterface;

/**
 * In-memory object storage whose Last-Modified comes from the test's clock instead of time().
 *
 * A real object store stamps Last-Modified on its own clock, which in production is the app's clock. The plain
 * InMemoryFilesystemAdapter stamps the real date, so a test on the frozen clock (TestClock) would see every file as
 * written in its future and a prune by age would never find anything. A write that names its own `timestamp` keeps it
 * (writeStream() goes through write()).
 */
final class ClockedInMemoryFilesystemAdapter extends InMemoryFilesystemAdapter
{
    public function __construct(
        readonly private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        parent::write($path, $contents, $this->stamped($config));
    }

    private function stamped(Config $config): Config
    {
        return $config->withDefaults(['timestamp' => $this->clock->now()->getTimestamp()]);
    }
}
