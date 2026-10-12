<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SpeedPuzzling\Web\Services\PlatformDetector;
use SplFileInfo;

/**
 * Who reads the platform flags (docs/features/navigation NAV-14 (b), GEST-09, implementation plan §3.5 item 2):
 * `<html data-native-app>` (inside the app), `<html data-os>` (the device OS) and `html.is-standalone` are read in JS
 * only through assets/platform.js; app.js's old globals are only written, never read; the app's user-agent markers
 * live in PlatformDetector and the data-os head script only; Symfony UX Native is not installed; every native-aware
 * module imports its answer from assets/platform.js. GEST-10 adds: `navigator.vibrate` only in assets/haptics.js.
 */
final class PlatformFlagReadersTest extends TestCase
{
    /**
     * Modules that branch on the native app. Each imports isNativeApp() / nativePlatform() from assets/platform.js and
     * never sniffs the user agent. Every gesture, layer and haptic item appends its file (plan §3.5 item 4); the final
     * list is compared with README §4.14 (b) when NAV-14 is re-run in the Instant Back phase.
     */
    public const array NATIVE_AWARE_MODULES = [
        'assets/app.js',
        'assets/controllers/barcode_scanner_controller.js',
    ];

    private const string PLATFORM_MODULE = 'assets/platform.js';

    /** Inline head scripts (templates/_head_flags.html.twig): no imports possible, they read <html> themselves */
    private const string HEAD_SCRIPTS = 'assets/head/';

    /** What only assets/platform.js may read */
    private const array FLAG_READS = [
        'data-native-app' => '~data-native-app~',
        'dataset.nativeApp' => '~\bdataset\s*(\.\s*nativeApp\b|\[\s*[\'"]nativeApp[\'"])~',
        'data-os' => '~\bdata-os\b~',
        'dataset.os' => '~\bdataset\s*(\.\s*os\b|\[\s*[\'"]os[\'"])~',
        'is-standalone' => '~is-standalone~',
    ];

    /** The classes app.js writes from the flag: nobody reads them back in JS (CSS may) */
    private const string APP_CLASSES = '~[\'"](native-app|platform-(web|ios|android))[\'"]~';

    private const string APP_JS = 'assets/app.js';

    public function testTheFlagsAreReadOnlyThroughThePlatformModule(): void
    {
        $offenders = [];

        foreach ($this->javascriptFiles() as $path => $code) {
            if ($path === self::PLATFORM_MODULE || str_starts_with($path, self::HEAD_SCRIPTS)) {
                continue;
            }

            foreach (self::FLAG_READS as $name => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $offenders[] = $path . ' reads ' . $name;
                }
            }

            if ($path !== self::APP_JS && preg_match(self::APP_CLASSES, $code) === 1) {
                $offenders[] = $path . ' reads the native-app / platform-* classes';
            }
        }

        self::assertSame([], $offenders, 'Read the platform through assets/platform.js (isNativeApp(), nativePlatform(), os(), isStandalone())');

        // The module itself does read them - this test would pass vacuously after a rename
        $platform = $this->read(self::PLATFORM_MODULE);
        self::assertStringContainsString("'data-native-app'", $platform);
        self::assertStringContainsString("'data-os'", $platform);
        self::assertStringContainsString("'is-standalone'", $platform);
    }

    public function testOnlyTheDataOsHeadScriptWritesTheDataOsAttribute(): void
    {
        $writers = [];

        foreach ($this->javascriptFiles() as $path => $code) {
            if (preg_match('~setAttribute\(\s*[\'"]data-os[\'"]~', $code) === 1) {
                $writers[] = $path;
            }
        }

        self::assertSame([self::HEAD_SCRIPTS . 'platform_os.js'], $writers);
    }

    public function testTheOldGlobalsAreOnlyWrittenByAppJs(): void
    {
        $offenders = [];
        $writes = 0;

        foreach ([...$this->javascriptFiles(), ...$this->templates()] as $path => $code) {
            preg_match_all('~window\.(isNativeApp|nativePlatform)\b(?<rest>\s*=(?!=))?~', $code, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if ($path === self::APP_JS && ($match['rest'] ?? '') !== '') {
                    $writes++;

                    continue;
                }

                $offenders[] = $path . ': ' . trim($match[0]);
            }
        }

        self::assertSame([], $offenders, 'window.isNativeApp / window.nativePlatform are kept for old code only - read assets/platform.js');
        self::assertSame(2, $writes, 'app.js derives both globals from assets/platform.js');
        self::assertMatchesRegularExpression(
            '~import\s*\{[^}]*\bnativePlatform\b[^}]*\}\s*from\s*[\'"]\./platform\.js[\'"]~',
            $this->read(self::APP_JS),
        );
    }

    public function testTheAppMarkersLiveInTheDetectorAndTheDataOsHeadScriptOnly(): void
    {
        $markers = [...PlatformDetector::IOS_MARKERS, ...PlatformDetector::ANDROID_MARKERS];
        $found = [];

        foreach (['src', 'config', 'templates', 'assets'] as $directory) {
            foreach ($this->files($directory, ['php', 'twig', 'js', 'mjs', 'yaml', 'yml']) as $path => $code) {
                foreach ($markers as $marker) {
                    if (str_contains($code, $marker)) {
                        $found[$path] = true;
                    }
                }
            }
        }

        ksort($found);
        self::assertSame(
            [self::HEAD_SCRIPTS . 'platform_os.js', 'src/Services/PlatformDetector.php'],
            array_keys($found),
            'The user-agent markers of the app: PlatformDetector::IOS_MARKERS / ANDROID_MARKERS and their copy in the data-os head script',
        );
    }

    public function testSymfonyUxNativeIsNotInstalled(): void
    {
        foreach (['composer.json', 'package.json'] as $file) {
            self::assertStringNotContainsString('ux-native', $this->read($file), $file . ': Symfony UX Native is experimental and not installed (README §4.14)');
        }
    }

    public function testEveryNativeAwareModuleAsksThePlatformModule(): void
    {
        foreach (self::NATIVE_AWARE_MODULES as $module) {
            $code = $this->read($module);
            $relative = str_repeat('../', substr_count(substr($module, strlen('assets/')), '/'));
            $from = $relative === '' ? './platform.js' : $relative . 'platform.js';

            self::assertMatchesRegularExpression(
                '~import\s*\{[^}]*\b(isNativeApp|nativePlatform)\b[^}]*\}\s*from\s*[\'"]' . preg_quote($from, '~') . '[\'"]~',
                $code,
                $module . ' must import isNativeApp / nativePlatform from assets/platform.js',
            );
            self::assertStringNotContainsString('navigator.userAgent', $code, $module . ' must not sniff the user agent');
        }
    }

    /**
     * @return array<string, string> path relative to the project => contents
     */
    private function javascriptFiles(): array
    {
        return $this->files('assets', ['js', 'mjs']);
    }

    /**
     * @return array<string, string>
     */
    private function templates(): array
    {
        return $this->files('templates', ['twig']);
    }

    /**
     * @param list<string> $extensions
     * @return array<string, string>
     */
    private function files(string $directory, array $extensions): array
    {
        $root = dirname(__DIR__);
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $path = substr($file->getPathname(), strlen($root) + 1);

            if (str_starts_with($path, 'assets/vendor/')) {
                continue;
            }

            $files[$path] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/' . $path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
