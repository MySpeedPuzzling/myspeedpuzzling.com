<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a player pasted as "the link of my Google sheet" (docs/features/data-import/google-sheets.md "The link"): the
 * document's id is cut out of it and **nothing else of it is ever used** - the address we ask Google for is built by
 * us from the id alone.
 *
 * Taken: any address of a spreadsheet on docs.google.com - `/spreadsheets/d/<id>` with whatever follows (`/edit`,
 * `/view`, `/htmlview`, `/preview`, `/pubhtml`, `/copy`, a `?usp=…` of the phone app, a `#gid=…`), with or without
 * the account part (`/spreadsheets/u/1/d/<id>`), with or without `https://`.
 * Refused: documents, slides, forms, Drive folders and files, every other host - and a "published to the web" link
 * (`/spreadsheets/d/e/2PACX-…`), which carries another id that the export does not take: it gets a sentence of its
 * own.
 */
readonly final class GoogleSheetLink
{
    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{20,100}\z/';

    public const string NOT_A_SHEET = 'google_link';
    public const string PUBLISHED = 'google_published';

    private function __construct(
        public null|string $spreadsheetId,
        /** NOT_A_SHEET or PUBLISHED when there is no id */
        public null|string $problem,
    ) {
    }

    public static function parse(string $pasted): self
    {
        $link = trim($pasted);

        // Longer than any real link, or with what no link has
        if ($link === '' || strlen($link) > 2000 || preg_match('/[\s\x00-\x1f\x7f]/', $link) === 1) {
            return new self(null, self::NOT_A_SHEET);
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $link) !== 1) {
            $link = 'https://' . $link;
        }

        $parts = parse_url($link);

        if (
            $parts === false
            || in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true) === false
            || strtolower($parts['host'] ?? '') !== 'docs.google.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && in_array($parts['port'], [80, 443], true) === false)
        ) {
            return new self(null, self::NOT_A_SHEET);
        }

        $path = $parts['path'] ?? '';

        if (preg_match('#^/spreadsheets/(?:u/\d{1,3}/)?d/e/[A-Za-z0-9_-]+(?:/|$)#', $path) === 1) {
            return new self(null, self::PUBLISHED);
        }

        if (preg_match('#^/spreadsheets/(?:u/\d{1,3}/)?d/([A-Za-z0-9_-]{20,100})(?:/|$)#', $path, $match) !== 1) {
            return new self(null, self::NOT_A_SHEET);
        }

        return new self($match[1], null);
    }

    public static function isSpreadsheetId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }
}
