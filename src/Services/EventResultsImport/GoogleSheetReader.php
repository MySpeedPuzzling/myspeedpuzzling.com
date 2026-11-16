<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetTab;
use SpeedPuzzling\Web\Value\EventResultsImport\UnreadableSheetTab;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads an organizer's results sheet shared "Anyone with the link: Viewer" - no Google account, no key
 * (docs/features/event-results-import/README.md "What we read"): the public htmlview page lists the tabs with their
 * gids, each tab comes as CSV. The htmlview page is not a documented API; if Google changes it, tabs() fails loudly
 * and the fallback is the Sheets API v4 with a plain API key.
 */
readonly final class GoogleSheetReader
{
    private const string BASE_URL = 'https://docs.google.com/spreadsheets/d/';

    // One items.push() per tab; the name is a JavaScript string
    private const string TAB_PATTERN = '/items\.push\(\{name: "((?:[^"\\\\]|\\\\.)*)", pageUrl: "(?:[^"\\\\]|\\\\.)*", gid: "(\d+)"/';

    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    /**
     * Every tab with its CSV. A tab whose CSV does not come is kept aside as unreadable - the others are read anyway.
     *
     * @throws GoogleSheetUnreadable when the sheet's tabs cannot be listed
     */
    public function snapshot(string $spreadsheetId): SheetSnapshot
    {
        $tabs = [];
        $unreadable = [];
        $sheetTabs = $this->tabs($spreadsheetId);

        // Every tab's CSV is asked for at once - the check waits for the slowest tab, not for the sum of them all
        // (the organizer watches the button meanwhile)
        $responses = [];

        foreach ($sheetTabs as $index => $tab) {
            try {
                $responses[$index] = $this->send($this->csvUrl($spreadsheetId, $tab->gid));
            } catch (GoogleSheetUnreadable $e) {
                $responses[$index] = $e;
            }
        }

        foreach ($sheetTabs as $index => $tab) {
            try {
                $response = $responses[$index];

                if ($response instanceof GoogleSheetUnreadable) {
                    throw $response;
                }

                $tabs[] = new SheetSnapshotTab($tab->gid, $tab->title, $this->read($response, $this->csvUrl($spreadsheetId, $tab->gid), 'text/csv'));
            } catch (GoogleSheetUnreadable $e) {
                $unreadable[] = new UnreadableSheetTab($tab->gid, $tab->title, $e->getMessage());
            }
        }

        return new SheetSnapshot($tabs, $unreadable);
    }

    /**
     * @return list<SheetTab> never empty
     * @throws GoogleSheetUnreadable
     */
    public function tabs(string $spreadsheetId): array
    {
        $page = $this->get(self::BASE_URL . self::spreadsheetId($spreadsheetId) . '/htmlview', 'text/html');

        if (preg_match_all(self::TAB_PATTERN, $page, $matches, PREG_SET_ORDER) < 1) {
            throw new GoogleSheetUnreadable(sprintf('No tabs on the public page of spreadsheet %s - not shared with the link, or Google changed the page.', $spreadsheetId));
        }

        return array_map(
            static fn (array $match): SheetTab => new SheetTab($match[2], self::javaScriptString($match[1])),
            $matches,
        );
    }

    /**
     * @throws GoogleSheetUnreadable
     */
    public function csv(string $spreadsheetId, string $gid): string
    {
        if (preg_match('/^\d{1,12}$/', $gid) !== 1) {
            throw new GoogleSheetUnreadable(sprintf('"%s" is not a tab id.', $gid));
        }

        return $this->get($this->csvUrl($spreadsheetId, $gid), 'text/csv');
    }

    /**
     * @throws GoogleSheetUnreadable
     */
    private function csvUrl(string $spreadsheetId, string $gid): string
    {
        if (preg_match('/^\d{1,12}$/', $gid) !== 1) {
            throw new GoogleSheetUnreadable(sprintf('"%s" is not a tab id.', $gid));
        }

        return self::BASE_URL . self::spreadsheetId($spreadsheetId) . '/export?format=csv&gid=' . $gid;
    }

    /**
     * @throws GoogleSheetUnreadable
     */
    private function get(string $url, string $expectedContentType): string
    {
        return $this->read($this->send($url), $url, $expectedContentType);
    }

    /**
     * Starts the request - the client answers it lazily, so several can run at once.
     *
     * @throws GoogleSheetUnreadable
     */
    private function send(string $url): ResponseInterface
    {
        try {
            return $this->client->request('GET', $url, [
                'timeout' => 10,
                'max_duration' => 30,
                'headers' => ['User-Agent' => 'MySpeedPuzzling event results import (+https://myspeedpuzzling.com)'],
            ]);
        } catch (ExceptionInterface $e) {
            throw new GoogleSheetUnreadable(sprintf('Google did not answer %s.', $url), previous: $e);
        }
    }

    /**
     * @throws GoogleSheetUnreadable
     */
    private function read(ResponseInterface $response, string $url, string $expectedContentType): string
    {
        try {
            $status = $response->getStatusCode();
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new GoogleSheetUnreadable(sprintf('Google did not answer %s.', $url), previous: $e);
        }

        // A sheet not shared with the link answers Google's sign-in page - never the CSV
        if ($status !== 200 || !str_starts_with(strtolower($contentType), $expectedContentType)) {
            throw new GoogleSheetUnreadable(sprintf('Google answered %s with %d %s - is the sheet shared "Anyone with the link"?', $url, $status, $contentType));
        }

        return $content;
    }

    /**
     * @throws GoogleSheetUnreadable
     */
    private static function spreadsheetId(string $spreadsheetId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,100}$/', $spreadsheetId) !== 1) {
            throw new GoogleSheetUnreadable(sprintf('"%s" is not a spreadsheet id.', $spreadsheetId));
        }

        return $spreadsheetId;
    }

    /**
     * The tab's name as Google writes it into the page: \/, \", \\, \xHH and \uXXXX escapes.
     */
    private static function javaScriptString(string $escaped): string
    {
        $json = preg_replace('/\\\\x([0-9a-fA-F]{2})/', '\\\\u00$1', $escaped) ?? $escaped;
        $decoded = json_decode('"' . $json . '"');

        return is_string($decoded) ? $decoded : stripslashes($escaped);
    }
}
