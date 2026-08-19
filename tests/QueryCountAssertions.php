<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Query budgets for API endpoints (docs/features/api/v1-expansion-plan.md, N3):
 * a list must cost the same number of queries at 5 items as at 100.
 *
 * Usage: create tokens and seed data first, call startCountingQueries() right
 * before the request, assert after it. The first request of a test reuses the
 * kernel the helpers booted, so the profiler's db collector would also see the
 * helpers' own queries (token lookup, INSERT, COMMIT) - the debug data holder
 * is reset so that only the request is counted.
 */
trait QueryCountAssertions
{
    protected function startCountingQueries(KernelBrowser $browser): void
    {
        $browser->enableProfiler();

        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = $container->get('doctrine.debug_data_holder'); // @phpstan-ignore symfonyContainer.privateService
        $debugDataHolder->reset();
    }

    protected function queryCount(KernelBrowser $browser): int
    {
        $profile = $browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'The profiler did not collect the request - call startCountingQueries() before the request.');

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    protected function assertQueryCountAtMost(KernelBrowser $browser, int $max, string $why): void
    {
        $count = $this->queryCount($browser);

        self::assertLessThanOrEqual($max, $count, sprintf('%s: expected at most %d queries, the request ran %d.', $why, $max, $count));
    }
}
