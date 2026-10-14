<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The production image serves the finish detector's files itself (docs/features/online-competitions/README.md §5.7
 * "Downloads"): .docker/online-detector/online-detector.caddyfile, imported by the base image's Caddyfile. It is a site
 * block for a path of `:8080`, because the base imports /etc/frankenphp/Caddyfile.d/ outside its own site block - Caddy
 * calls a path in a site address deprecated. This adapts the base image's real Caddyfile with the rule, with the
 * FrankenPHP the suite runs in (the base image, locally and in CI), so a base image that drops the import, or a Caddy
 * that drops the path, fails here before an image that serves nothing is deployed.
 *
 * A missing binary fails instead of skipping - a skipped guard guards nothing.
 */
final class OnlineDetectorCaddyRuleTest extends TestCase
{
    private const string BASE_CADDYFILE = '/etc/caddy/Caddyfile';
    private const string IMPORT = 'import /etc/frankenphp/Caddyfile.d/*.caddyfile';
    private const string RULE = __DIR__ . '/../.docker/online-detector/online-detector.caddyfile';

    public function testTheDetectorsFilesAreServedImmutableAndPrecompressedBeforePhp(): void
    {
        $routes = self::adaptedRoutes();
        $first = $routes[0];

        self::assertSame([['path' => ['/online-detector/*']]], $first['match'] ?? null, 'the detector path is tried before the base block');
        self::assertTrue($first['terminal'] ?? false);

        $handlers = self::handlers($first);
        $fileServers = array_values(array_filter($handlers, static fn (array $handler): bool => $handler['handler'] === 'file_server'));

        self::assertCount(1, $fileServers);
        self::assertSame(['br', 'gzip'], $fileServers[0]['precompressed_order'] ?? null);
        self::assertNotContains('php', array_column($handlers, 'handler'), 'never PHP');

        $headers = array_values(array_filter($handlers, static fn (array $handler): bool => $handler['handler'] === 'headers'));
        $set = array_map(static fn (array $handler): mixed => $handler['response'] ?? null, $headers);

        self::assertContains(['set' => ['Cache-Control' => ['public, max-age=31536000, immutable']]], $set);
        self::assertContains(['set' => ['Cache-Control' => ['no-store']]], $set, 'a missing file is never pinned');
        self::assertContains(['deferred' => true, 'set' => ['Content-Type' => ['application/octet-stream']]], $set);

        // The rest of the site is the base block's, as before
        foreach (array_slice($routes, 1) as $route) {
            self::assertStringNotContainsString('online-detector', (string) json_encode($route));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function adaptedRoutes(): array
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        self::assertIsString($frankenphp, 'frankenphp is required to adapt the Caddyfile - it is part of the base image');
        self::assertFileExists(self::BASE_CADDYFILE);

        $base = (string) file_get_contents(self::BASE_CADDYFILE);

        self::assertStringContainsString(self::IMPORT, $base, 'the base image no longer imports Caddyfile.d - the image would serve the detector through PHP, without its headers');

        $caddyfile = (string) tempnam(sys_get_temp_dir(), 'msp-caddyfile-');

        try {
            file_put_contents($caddyfile, str_replace(self::IMPORT, 'import ' . (string) realpath(self::RULE), $base));

            $process = new Process([$frankenphp, 'adapt', '--config', $caddyfile, '--adapter', 'caddyfile'], env: ['FRANKENPHP_CONFIG' => '', 'CADDY_GLOBAL_OPTIONS' => '']);
            $process->mustRun();
        } finally {
            @unlink($caddyfile);
        }

        /** @var array{apps: array{http: array{servers: array<string, array{listen: list<string>, routes: list<array<string, mixed>>}>}}} $config */
        $config = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($config['apps']['http']['servers'] as $server) {
            if (in_array(':8080', $server['listen'], true)) {
                return $server['routes'];
            }
        }

        self::fail('no :8080 server in the adapted Caddyfile');
    }

    /**
     * Every handler of a route, subroutes flattened
     *
     * @param array<string, mixed> $route
     * @return list<array{handler: string, response?: mixed, precompressed_order?: mixed}>
     */
    private static function handlers(array $route): array
    {
        $found = [];

        /** @var list<array<string, mixed>> $handle */
        $handle = $route['handle'] ?? [];

        foreach ($handle as $handler) {
            if (($handler['handler'] ?? null) === 'subroute') {
                /** @var list<array<string, mixed>> $subroutes */
                $subroutes = $handler['routes'] ?? [];

                foreach ($subroutes as $subroute) {
                    $found = [...$found, ...self::handlers($subroute)];
                }

                continue;
            }

            /** @var array{handler: string, response?: mixed, precompressed_order?: mixed} $handler */
            $found[] = $handler;
        }

        return $found;
    }
}
