<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * One owner of the browser history next to Turbo (docs/features/navigation GEST-01 (a), implementation plan §3.4
 * item 1): only assets/layer_history.js pushes history entries or touches Turbo.session.history. A second owner
 * resuming Turbo's history early turns the next Back into a Turbo restore = a full reload (assets/app.js), and a
 * push of its own leaves [A, A', B] behind. Layers call open / requestClose / visitFromLayer, page owners (Results)
 * pauseTurboHistory / pushPageState. CloseWatcher is not a path (Safari has none): Back goes through the same entry.
 *
 * Moved onto the helper by GEST-01: dynamic_modal_controller.js (claimHistory / handlePopState / resumeTurboHistory),
 * live_results_controller.js (its own pushState + stop()), achievement_sheet_controller.js (its CloseWatcher).
 */
final class TurboHistoryOwnershipTest extends TestCase
{
    private const string OWNER = 'assets/layer_history.js';

    /** What only the owner may do */
    private const array OWNED = [
        'history.pushState' => '~\bpushState\s*\(~',
        'Turbo.session.history' => '~\bsession\s*\??\.\s*history\b~',
        'session[\'history\']' => '~\bsession\s*\[\s*[\'"]history[\'"]\s*\]~',
    ];

    public function testOnlyTheLayerHistoryModuleOwnsHistoryEntriesAndTurbosHistory(): void
    {
        $offenders = [];

        foreach ($this->files() as $path => $code) {
            if ($path === self::OWNER) {
                continue;
            }

            foreach (self::OWNED as $what => $pattern) {
                if (preg_match($pattern, $this->withoutComments($code)) === 1) {
                    $offenders[] = $path . ': ' . $what;
                }
            }
        }

        self::assertSame([], $offenders, 'Go through assets/layer_history.js (open / requestClose / pauseTurboHistory / pushPageState)');
    }

    public function testTheOwnerReallyIsTheOneUsingThem(): void
    {
        $code = $this->files()[self::OWNER] ?? null;
        self::assertIsString($code, self::OWNER . ' is missing');
        self::assertMatchesRegularExpression(self::OWNED['history.pushState'], $code);
        self::assertStringContainsString('session.history', $code);
    }

    public function testCloseWatcherIsNotAPath(): void
    {
        $offenders = [];

        foreach ($this->files() as $path => $code) {
            if (str_contains($code, 'CloseWatcher')) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'Back closes layers through the history entry of assets/layer_history.js (Safari has no CloseWatcher)');
    }

    /** Inline scripts in templates neither (head scripts are page-independent and never touch the history) */
    public function testNoTemplatePushesHistoryEntries(): void
    {
        $offenders = [];

        foreach ($this->files('templates', ['twig']) as $path => $code) {
            if (preg_match(self::OWNED['history.pushState'], $code) === 1 || preg_match(self::OWNED['Turbo.session.history'], $code) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders);
    }

    private function withoutComments(string $code): string
    {
        // Block comments and whole-line // comments: a doc line naming the API is no call of it
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $code);

        return (string) preg_replace('~^\s*//.*$~m', '', $code);
    }

    /**
     * @param list<string> $extensions
     * @return array<string, string> path relative to the project => contents
     */
    private function files(string $directory = 'assets', array $extensions = ['js', 'mjs']): array
    {
        $root = dirname(__DIR__);
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }
}
