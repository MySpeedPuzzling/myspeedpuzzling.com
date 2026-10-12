<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The other native dialogs (docs/features/navigation GEST-13 "Other native dialogs", review F16, decision 96): no
 * alert / prompt call in templates/ or assets/ outside the committed allow-list - iOS Safari refuses those after a
 * Back to a pushState entry just like confirm (NativeConfirmAllowlistTest). The barcode scanner's "no camera" message
 * is an in-page notice (the page's toast, barcode_scanner.no_camera in 6 locales).
 *
 * Not covered on purpose: `beforeunload` prompts (live results, results desk, participants sheet, seating) - browser UI
 * for a real unload, cannot be in-page, and iOS Safari does not show them (logged exception).
 */
final class NativeDialogAllowlistTest extends TestCase
{
    /** A call of window.alert / window.prompt, with or without `window.` - a method of another object is no hit */
    public const string PATTERN = '~(?<![\w.$])(?:window\.)?(?:alert|prompt)\(~';

    /**
     * file => hits
     */
    private const array ALLOWED = [
        // Inside the Hotwire Native app only (in-app purchases); the app shows its own native alert, no Safari involved
        'assets/controllers/native_billing_controller.js' => 5,
    ];

    private const array EXTENSIONS = ['twig', 'html', 'js', 'mjs', 'cjs', 'ts'];

    private const string SCANNER_ROOT = '~data-controller="[^"]*\bbarcode-scanner\b[^"]*"~';
    private const string NO_CAMERA_VALUE = "data-barcode-scanner-no-camera-value=\"{{ 'barcode_scanner.no_camera'|trans }}\"";

    public function testTheHitsEqualTheAllowList(): void
    {
        $hits = [];

        foreach ($this->files(['templates', 'assets']) as $path => $code) {
            $count = preg_match_all(self::PATTERN, $code);

            if ($count > 0) {
                $hits[$path] = $count;
            }
        }

        ksort($hits);

        self::assertSame(self::ALLOWED, $hits, 'A native alert/prompt: show the message in the page (a toast, an inline notice) instead');
    }

    public function testThePatternFindsCallsOnly(): void
    {
        foreach (["alert('x')", 'window.alert(message)', '  prompt("Name?")', '(alert(1))'] as $call) {
            self::assertSame(1, preg_match(self::PATTERN, $call), $call);
        }

        foreach (['this.alert(x)', 'Swal.alert(x)', 'role="alert"', 'class="alert alert-info"', 'showAlert(x)', 'prompt_text(x)', '$alert(x)'] as $other) {
            self::assertSame(0, preg_match(self::PATTERN, $other), $other);
        }
    }

    /**
     * Every scanner gets its "no camera" text from the page - a scanner without it would show an empty notice.
     */
    public function testEveryBarcodeScannerCarriesTheNoCameraNotice(): void
    {
        $roots = 0;
        $missing = [];

        foreach ($this->files(['templates']) as $path => $code) {
            $offset = 0;

            while (preg_match(self::SCANNER_ROOT, $code, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $roots++;
                $at = $match[0][1];
                $offset = $at + 1;
                $tagStart = strrpos(substr($code, 0, $at), '<');
                $tag = substr($code, (int) $tagStart, (int) strpos($code, '>', $at) - (int) $tagStart + 1);

                if (!str_contains($tag, self::NO_CAMERA_VALUE)) {
                    $missing[] = $path;
                }
            }
        }

        self::assertSame([], $missing);
        self::assertGreaterThanOrEqual(5, $roots);

        $controller = (string) file_get_contents(dirname(__DIR__) . '/assets/controllers/barcode_scanner_controller.js');
        self::assertStringContainsString('noCamera: { type: String', $controller);
        self::assertSame(2, substr_count($controller, 'this._noCameraNotice();'));
    }

    /**
     * @param list<string> $directories
     * @return array<string, string> path relative to the project => contents
     */
    private function files(array $directories): array
    {
        $root = dirname(__DIR__);
        $files = [];

        foreach ($directories as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
