<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * The service worker answers its stored offline copy of the live result entry page (competition toolkit C6 -
 * docs/features/competitions-management/toolkit/C-judge-page.md §1.4) for live entry URLs only, recognised by fixed
 * patterns in public/service-worker.js. This generates every path the router has for the live entry routes - every
 * locale of `live_results` and `live_results_event`, the printed name tag `live_results_scan` and every other route
 * under `/live/{competitionId}/` (the table / line-up / entry tags, toolkit B2, are picked up as soon as they exist) -
 * and asserts the worker and assets/live_entry_route.js both read each of them, in the locale of its copy, and that
 * neither reads the copy's own URLs.
 */
final class ServiceWorkerLiveRoutesTest extends KernelTestCase
{
    private const array LIVE_ROUTES = ['live_results', 'live_results_event', 'live_results_scan'];
    private const array LOCALES = ['en', 'cs', 'es', 'ja', 'fr', 'de'];
    private const string SAMPLE_ID = '018d0020-0000-7000-8000-00000000abcd';

    public function testEveryLiveEntryPathOfTheRouterIsReadByTheWorkerAndThePage(): void
    {
        $expected = $this->liveEntryPaths();

        // 6 + 6 locales of the round and the event page, 6 of the printed name tag
        self::assertGreaterThanOrEqual(18, count($expected));

        $worker = ServiceWorkerLiveShellTest::routesOf(array_keys($expected));
        $page = self::pageRoutesOf(array_keys($expected));

        foreach ($expected as $path => $locale) {
            self::assertSame(['locale' => $locale], $worker[$path], 'the service worker: ' . $path);
            self::assertNotNull($page[$path], 'parseLiveEntryRoute(): ' . $path);
            self::assertSame($locale, $page[$path]['locale'] ?? null, 'parseLiveEntryRoute(): ' . $path);
        }
    }

    public function testTheCopysOwnUrlsAreNoLiveEntryPath(): void
    {
        $router = $this->router();
        $paths = [];

        foreach (self::LOCALES as $locale) {
            $paths[] = $router->generate('live_results_shell', ['_locale' => $locale]);
        }

        self::assertCount(6, array_unique($paths));

        foreach (ServiceWorkerLiveShellTest::routesOf($paths) as $path => $route) {
            self::assertNull($route, $path);
        }

        foreach (self::pageRoutesOf($paths) as $path => $route) {
            self::assertNull($route, $path);
        }
    }

    /**
     * @return array<string, null|string> path => the locale of the copy the worker answers (null = the path has none)
     */
    private function liveEntryPaths(): array
    {
        $router = $this->router();
        $paths = [];

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $canonical = $route->getDefault('_canonical_route');
            $baseName = is_string($canonical) ? $canonical : $name;

            if (!in_array($baseName, self::LIVE_ROUTES, true) && preg_match('#^(/\{_locale\})?/live/\{competitionId\}/#', $route->getPath()) !== 1) {
                continue;
            }

            foreach ($this->pathsOf($route) as $path => $locale) {
                $matched = $router->match($path);
                self::assertSame($baseName, $matched['_route'], 'the sample path belongs to its route: ' . $path);
                $paths[$path] = $locale;
            }
        }

        return $paths;
    }

    /**
     * @return array<string, null|string>
     */
    private function pathsOf(Route $route): array
    {
        $path = $route->getPath();
        $locales = [null];

        if (str_contains($path, '{_locale}')) {
            $requirement = $route->getRequirement('_locale') ?? '[a-z]{2}';
            $locales = array_values(array_filter(self::LOCALES, static fn (string $locale): bool => preg_match('#^(?:' . $requirement . ')$#', $locale) === 1));
            self::assertNotSame([], $locales, $path);
        }

        $paths = [];

        foreach ($locales as $locale) {
            $sample = (string) preg_replace_callback('#\{([^}]+)\}#', static function (array $match) use ($locale, $path): string {
                return match (true) {
                    $match[1] === '_locale' => (string) $locale,
                    str_ends_with($match[1], 'Id') => self::SAMPLE_ID,
                    $match[1] === 'table' || $match[1] === 'tableNumber' => '12',
                    default => throw new \LogicException(sprintf('No sample for {%s} in %s - add one', $match[1], $path)),
                };
            }, $path);

            // A localized route (`live_results.cs`) carries its locale in its words, not as a parameter
            $routeLocale = $route->getDefault('_locale');
            $paths[$sample] = $locale ?? (is_string($route->getDefault('_canonical_route')) && is_string($routeLocale) ? $routeLocale : null);
        }

        return $paths;
    }

    private function router(): RouterInterface
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get(RouterInterface::class);

        return $router;
    }

    /**
     * parseLiveEntryRoute() of assets/live_entry_route.js for each path.
     *
     * @param list<string> $paths
     * @return array<string, null|array<string, mixed>>
     */
    private static function pageRoutesOf(array $paths): array
    {
        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required - it is part of the base image');

        $script = <<<'JS'
            import { readFileSync } from 'node:fs';
            const { parseLiveEntryRoute } = await import(process.argv[1]);
            const paths = JSON.parse(readFileSync(0, 'utf8'));
            process.stdout.write(JSON.stringify(Object.fromEntries(paths.map((path) => [path, parseLiveEntryRoute(path)]))));
            JS;

        $process = new Process([$node, '--input-type=module', '-e', $script, 'file://' . dirname(__DIR__) . '/assets/live_entry_route.js']);
        $process->setInput((string) json_encode($paths));
        $process->mustRun();

        /** @var array<string, null|array<string, mixed>> */
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
