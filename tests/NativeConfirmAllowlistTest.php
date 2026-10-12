<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No native confirm dialog is reachable from a non-admin page (docs/features/navigation GEST-13): iOS Safari silently
 * refuses native dialogs after a Back to a pushState entry (hotwired/turbo#637), and the layer entries of GEST-01
 * create exactly that state. Templates confirm with `data-turbo-confirm` (+ `data-turbo-confirm-danger`), controllers
 * with `await confirmDialog(…)` / `guardLeave()` of assets/confirm_dialog.js.
 *
 * The requirement's Verify, literally: grep templates/ and assets/ for the pattern below; the hits must equal the
 * committed allow-list. The dialog module and its watchdog call nothing by that name (the watchdog wraps the
 * function it finds on window and calls it through `original.apply()`), so they need no entry.
 */
final class NativeConfirmAllowlistTest extends TestCase
{
    /** requirements.md GEST-13 Verify */
    public const string PATTERN = '~\bwindow\.confirm\(|[(!=&|[:space:]]confirm\(~';

    /**
     * /admin may keep native confirms (requirement GEST-13): admins only, desktop. file => hits
     */
    private const array ADMIN = [
        'templates/admin/moderators.html.twig' => 1,
        'templates/admin/oauth2_client_request_detail.html.twig' => 2,
        'templates/admin/referral_detail.html.twig' => 1,
    ];

    /**
     * Method definitions named confirm - not calls. file => hits; every hit line must read like a definition.
     */
    private const array METHOD_DEFINITIONS = [
        // PreviewDialog#confirm() - the participants sheet's own <dialog>
        'assets/participants_sheet/preview_dialog.js' => 1,
        // SheetModel#confirm(groupId, deletedTeams)
        'assets/participants_sheet/sheet_model.js' => 1,
        // the copy-link controller's "copied" confirmation
        'assets/controllers/copy_link_controller.js' => 1,
    ];

    private const string METHOD_DEFINITION = '~^\s*(async\s+)?confirm\([^)]*\)\s*\{~';

    private const array EXTENSIONS = ['twig', 'html', 'js', 'mjs', 'cjs', 'ts'];

    public function testTheHitsEqualTheAllowList(): void
    {
        $hits = [];

        foreach ($this->files() as $path => $code) {
            $count = preg_match_all(self::PATTERN, $code);

            if ($count > 0) {
                $hits[$path] = $count;
            }
        }

        $allowed = [...self::ADMIN, ...self::METHOD_DEFINITIONS];
        ksort($hits);
        ksort($allowed);

        self::assertSame($allowed, $hits, 'A native confirm outside /admin: use data-turbo-confirm on the form or its submitter, or await confirmDialog() from assets/confirm_dialog.js');
    }

    public function testTheAllowedAdminHitsAreAdminTemplates(): void
    {
        foreach (array_keys(self::ADMIN) as $path) {
            self::assertStringStartsWith('templates/admin/', $path);
        }
    }

    public function testTheMethodDefinitionsAreNoCalls(): void
    {
        foreach (self::METHOD_DEFINITIONS as $path => $count) {
            $lines = array_values(array_filter(
                explode("\n", $this->read($path)),
                static fn (string $line): bool => preg_match(self::PATTERN, $line) === 1,
            ));

            self::assertCount($count, $lines, $path);

            foreach ($lines as $line) {
                self::assertMatchesRegularExpression(self::METHOD_DEFINITION, $line, $path . ': not a method definition');
            }
        }
    }

    /**
     * Turbo skips a form or submitter under data-turbo="false" - its data-turbo-confirm would never ask. The tag itself
     * and the form around a confirming submitter (the rendered pages are checked in the converted pages' tests).
     */
    public function testNoConfirmationSitsWhereTurboDoesNotSubmit(): void
    {
        $offenders = [];
        $confirmations = 0;

        foreach ($this->files() as $path => $code) {
            if (!str_starts_with($path, 'templates/')) {
                continue;
            }

            $offset = 0;

            while (($at = strpos($code, 'data-turbo-confirm=', $offset)) !== false) {
                $confirmations++;
                $offset = $at + 1;
                $tagStart = strrpos(substr($code, 0, $at), '<');
                $tagEnd = strpos($code, '>', $at);
                self::assertNotFalse($tagStart, $path);
                self::assertNotFalse($tagEnd, $path);
                $tag = substr($code, $tagStart, $tagEnd - $tagStart + 1);

                if (preg_match('~data-turbo=["\']false~', $tag) === 1) {
                    $offenders[] = $path . ': ' . strtok($tag, "\n");
                }

                // A submitter: the <form> it belongs to (the last one opened before it and not closed since)
                if (preg_match('~^<(button|input)\b~', $tag) === 1) {
                    $before = substr($code, 0, $tagStart);
                    $formStart = strrpos($before, '<form');

                    if ($formStart !== false && !str_contains(substr($before, $formStart), '</form>')) {
                        $formTag = substr($before, $formStart, (int) strpos($before, '>', $formStart) - $formStart + 1);

                        if (preg_match('~data-turbo=["\']false~', $formTag) === 1) {
                            $offenders[] = $path . ': the form around ' . strtok($tag, "\n");
                        }
                    }
                }
            }
        }

        self::assertSame([], $offenders);
        // 14 converted sites + the delete of the edit-time modal (RoundTableManager's two Live buttons ask through
        // confirm_action_controller.js) - this test would pass vacuously after a rename
        self::assertGreaterThanOrEqual(15, $confirmations);
    }

    /**
     * @return array<string, string> path relative to the project => contents
     */
    private function files(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        foreach (['templates', 'assets'] as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $path = substr($file->getPathname(), strlen($root) + 1);
                $files[$path] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $path);
    }
}
