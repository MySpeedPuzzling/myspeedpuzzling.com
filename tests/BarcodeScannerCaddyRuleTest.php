<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The production image serves the barcode scanner's zbar files itself (docs/features/barcode-scanner/README.md §5.9):
 * .docker/barcode-scanner/barcode-scanner.caddyfile, imported by the base image's Caddyfile like the finish detector's
 * rule (OnlineDetectorCaddyRuleTest - the same adaptation of the base image's real Caddyfile). The files themselves are
 * pinned too: the exact bytes of the npm packages, at the path the scanner loads.
 *
 * A missing binary fails instead of skipping - a skipped guard guards nothing.
 */
final class BarcodeScannerCaddyRuleTest extends TestCase
{
    private const string BASE_CADDYFILE = '/etc/caddy/Caddyfile';
    private const string IMPORT = 'import /etc/frankenphp/Caddyfile.d/*.caddyfile';
    private const string RULE = __DIR__ . '/../.docker/barcode-scanner/barcode-scanner.caddyfile';
    private const string DIRECTORY = __DIR__ . '/../public/barcode-scanner/zbar-wasm-0.9.15';

    // sha256 of the files in the npm tarballs (@undecaf/zbar-wasm 0.9.15, @undecaf/barcode-detector-polyfill 0.9.21,
    // whose sha512 integrity matched the registry's) - the same bytes jsdelivr serves, the scanner's fallback
    private const array FILES = [
        'zbar-wasm.js' => 'd465abb670f7735196546cee3a5617242d6a8630179747cc78d3b9c71f8b2a48',
        'zbar.wasm' => 'd779f39f75b96752a33deca9289c2d83754a1c7da21bc88605e2c084f87da771',
        'barcode-detector-polyfill.js' => '9c95fcc363e0f57a1aa474c739f8892bd995975cd9cec4dea9653014a62f8acd',
    ];

    public function testTheZbarFilesAreServedImmutableBeforePhp(): void
    {
        $routes = self::adaptedRoutes();
        $first = $routes[0];

        self::assertSame([['path' => ['/barcode-scanner/*']]], $first['match'] ?? null, 'the scanner path is tried before the base block');
        self::assertTrue($first['terminal'] ?? false);

        $handlers = self::handlers($first);
        $names = array_column($handlers, 'handler');

        self::assertContains('file_server', $names);
        self::assertContains('encode', $names);
        self::assertNotContains('php', $names, 'never PHP');

        $headers = array_values(array_filter($handlers, static fn (array $handler): bool => $handler['handler'] === 'headers'));
        $set = array_map(static fn (array $handler): mixed => $handler['response'] ?? null, $headers);

        self::assertContains(['set' => ['Cache-Control' => ['public, max-age=31536000, immutable']]], $set);
        self::assertContains(['set' => ['Cache-Control' => ['no-store']]], $set, 'a missing file is never pinned');

        foreach (array_slice($routes, 1) as $route) {
            self::assertStringNotContainsString('barcode-scanner', (string) json_encode($route));
        }
    }

    public function testTheFilesAreTheNpmPackagesBytesAtThePathTheScannerLoads(): void
    {
        foreach (self::FILES as $file => $sha256) {
            self::assertSame($sha256, hash_file('sha256', self::DIRECTORY . '/' . $file), $file . ' - a new version is a new directory, never new bytes here');
        }

        $controller = (string) file_get_contents(__DIR__ . '/../assets/controllers/barcode_scanner_controller.js');

        self::assertStringContainsString("'/barcode-scanner/zbar-wasm-0.9.15/zbar-wasm.js'", $controller);
        self::assertStringContainsString("'/barcode-scanner/zbar-wasm-0.9.15/barcode-detector-polyfill.js'", $controller);
        // zbar finds its .wasm next to the script that loaded it
        self::assertFileExists(self::DIRECTORY . '/zbar.wasm');
        self::assertFileExists(self::DIRECTORY . '/LICENSE-zbar-wasm.txt');
        self::assertFileExists(self::DIRECTORY . '/LICENSE-barcode-detector-polyfill.txt');
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

        self::assertStringContainsString(self::IMPORT, $base, 'the base image no longer imports Caddyfile.d - the image would serve zbar through PHP, without its headers');

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
