<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetTab;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

        return $this->get(self::BASE_URL . self::spreadsheetId($spreadsheetId) . '/export?format=csv&gid=' . $gid, 'text/csv');
    }

    /**
     * @throws GoogleSheetUnreadable
     */
    private function get(string $url, string $expectedContentType): string
    {
        try {
            $response = $this->client->request('GET', $url, [
                'timeout' => 10,
                'max_duration' => 30,
                'headers' => ['User-Agent' => 'MySpeedPuzzling event results import (+https://myspeedpuzzling.com)'],
            ]);

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
