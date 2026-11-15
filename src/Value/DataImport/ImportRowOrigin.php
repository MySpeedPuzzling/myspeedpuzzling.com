<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Controller\DataImport\ImportPuzzleGroupController;
use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use Symfony\Component\Routing\Requirement\Requirement;
use SpeedPuzzling\Web\Value\ReturnUrl;

/**
 * Where the player came to a row's editor from (docs/features/data-import/pairing-screens.md "The way back"): the
 * `?return=` of the link they followed, proven to be a screen of this very import - the editor's back link is named
 * after it, and a save leads back to it.
 *
 * Only a same-site path (ReturnUrl) that is **one of the pages a player can come from** survives: the GET pages of
 * this import listed in SCREENS - never a POST-only address, a fragment a frame loads, another row's editor or a
 * path that merely starts like the import's - with whatever query and anchor the screen had. Anything else is no
 * origin at all: the caller falls back to "Rows we could not read". A new screen that links to the editor is added
 * here (and gets its `data_import.row.back_to.<screen>` text).
 */
final readonly class ImportRowOrigin
{
    /** The path after the import's id (a pattern) => the screen, as `data_import.row.back_to.<screen>` names it */
    private const array SCREENS = [
        '' => 'overview',
        '/to-fix' => 'to_fix',
        '/columns' => 'check',
        '/sheets/{uuid}' => 'sheet',
        '/sheets/{uuid}/need-a-look' => 'sheet',
        '/people' => 'people',
        '/puzzles' => 'puzzles',
        '/puzzles/{group}' => 'puzzles',
        '/puzzle/{uuid}' => 'puzzle',
        '/add' => 'add',
        '/add/already-on' => 'add',
        '/add/list' => 'add_list',
        '/not-added' => 'not_added',
        '/not-added/times' => 'not_added',
        '/added' => 'added',
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
        $import = '/(?:' . ImportsHomeController::LOCALES . ')/import/(?i:' . preg_quote($importId, '#') . ')';

        foreach (self::SCREENS as $page => $screen) {
            $pattern = strtr(preg_quote($page, '#'), [
                '\{uuid\}' => '(?i:' . Requirement::UUID . ')',
                '\{group\}' => '(?:' . ImportPuzzleGroupController::GROUPS . ')',
            ]);

            if (preg_match('#^' . $import . $pattern . '$#', $path) === 1) {
                return new self($url, $screen);
            }
        }

        return null;
    }
}
