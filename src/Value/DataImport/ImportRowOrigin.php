<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Value\ReturnUrl;

/**
 * Where the player came to a row's editor from (docs/features/data-import/pairing-screens.md "The way back"): the
 * `?return=` of the link they followed, proven to be a screen of this very import - the editor's back link is named
 * after it, and a save leads back to it.
 *
 * Only a same-site path (ReturnUrl) of this import's own screens survives: `/{locale}/import/{importId}[/…]`, plain
 * path segments only (no `..`, no percent-encoding in the path), with whatever query and anchor the screen had. A
 * row's editor is never the way back from a row's editor. Anything else is no origin at all - the caller falls back
 * to "Rows we could not read".
 */
final readonly class ImportRowOrigin
{
    /** The first path segment after the import's id => the screen, as `data_import.row.back_to.<screen>` names it */
    private const array SCREENS = [
        '' => 'overview',
        'to-fix' => 'to_fix',
        'columns' => 'check',
        'sheets' => 'sheet',
        'people' => 'people',
        'puzzles' => 'puzzles',
        'puzzle' => 'puzzle',
        'add' => 'add',
        'not-added' => 'not_added',
        'added' => 'added',
    ];

    private function __construct(
        /** Safe to redirect and to link to */
        public string $url,
        public string $screen,
    ) {
    }

    public static function tryFrom(null|string $value, string $importId): null|self
    {
        $url = ReturnUrl::tryFrom($value)?->path;

        if ($url === null) {
            return null;
        }

        $path = substr($url, 0, strcspn($url, '?#'));
        $pattern = '#^/(?:' . ImportsHomeController::LOCALES . ')/import/(?i:' . preg_quote($importId, '#') . ')((?:/[A-Za-z0-9-]+)*)$#';

        if (preg_match($pattern, $path, $found) !== 1) {
            return null;
        }

        $segments = $found[1] === '' ? [] : explode('/', substr($found[1], 1));
        $first = $segments[0] ?? '';

        if ($first === 'rows') {
            return null;
        }

        $screen = self::SCREENS[$first] ?? 'other';

        if ($screen === 'add' && in_array($segments[1] ?? '', ['list', 'times'], true)) {
            $screen = 'add_' . $segments[1];
        }

        return new self($url, $screen);
    }
}
