<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventResultsImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleSheetReaderTest extends TestCase
{
    private const string SPREADSHEET_ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcd';

    public function testListsTheTabsWithTheirIdsAndNamesFromThePublicPage(): void
    {
        // The shape of Google's htmlview page: one items.push() per tab, names escaped as JavaScript strings
        $page = '<html><script>var items = [];'
            . 'items.push({name: "Maple Street 10\/5\/2026", pageUrl: "https:\/\/docs.google.com\/spreadsheets\/d\/x\/htmlview\/sheet?headers\x3dtrue&gid=0", gid: "0",initialSheet: ("0" == gid)});'
            . 'items.push({name: "Café \"Pairs\"", pageUrl: "https:\/\/docs.google.com\/spreadsheets\/d\/x\/htmlview\/sheet?headers\x3dtrue&gid=996511821", gid: "996511821",initialSheet: ("996511821" == gid)});'
            . '</script></html>';

        $requests = [];
        $reader = $this->reader([new MockResponse($page, ['response_headers' => ['content-type' => 'text/html']])], $requests);

        $tabs = $reader->tabs(self::SPREADSHEET_ID);

        self::assertCount(2, $tabs);
        self::assertSame('0', $tabs[0]->gid);
        self::assertSame('Maple Street 10/5/2026', $tabs[0]->title);
        self::assertSame('996511821', $tabs[1]->gid);
        self::assertSame('Café "Pairs"', $tabs[1]->title);
        self::assertSame(['GET https://docs.google.com/spreadsheets/d/' . self::SPREADSHEET_ID . '/htmlview'], $requests);
    }

    public function testAPageWithoutTabsMeansGoogleChangedItOrTheSheetIsNotShared(): void
    {
        $reader = $this->reader([new MockResponse('<html>Sign in</html>', ['response_headers' => ['content-type' => 'text/html']])]);

        $this->expectException(GoogleSheetUnreadable::class);
        $reader->tabs(self::SPREADSHEET_ID);
    }

    public function testReadsATabAsCsv(): void
    {
        $requests = [];
        $reader = $this->reader([new MockResponse("Name,Time\nPuzzle Pals,1:00:00\n", ['response_headers' => ['content-type' => 'text/csv']])], $requests);

        self::assertSame("Name,Time\nPuzzle Pals,1:00:00\n", $reader->csv(self::SPREADSHEET_ID, '996511821'));
        self::assertSame(['GET https://docs.google.com/spreadsheets/d/' . self::SPREADSHEET_ID . '/export?format=csv&gid=996511821'], $requests);
    }

    public function testASheetNotSharedWithTheLinkIsUnreadable(): void
    {
        // Not shared: Google answers its sign-in page (401), never a CSV
        $reader = $this->reader([new MockResponse('<html>Sign in</html>', ['http_code' => 401, 'response_headers' => ['content-type' => 'text/html']])]);

        $this->expectException(GoogleSheetUnreadable::class);
        $reader->csv(self::SPREADSHEET_ID, '0');
    }

    public function testAnHtmlAnswerToACsvRequestIsUnreadable(): void
    {
        $reader = $this->reader([new MockResponse('<html>Sign in</html>', ['response_headers' => ['content-type' => 'text/html; charset=utf-8']])]);

        $this->expectException(GoogleSheetUnreadable::class);
        $reader->csv(self::SPREADSHEET_ID, '0');
    }

    public function testAnUnreachableGoogleIsUnreadable(): void
    {
        $reader = $this->reader([new MockResponse('', ['error' => 'Connection timed out'])]);

        $this->expectException(GoogleSheetUnreadable::class);
        $reader->tabs(self::SPREADSHEET_ID);
    }

    public function testOnlyRealIdsAreRequested(): void
    {
        $requests = [];
        $reader = $this->reader([], $requests);

        try {
            $reader->csv('../../evil', '0');
            self::fail('An invalid spreadsheet id must not be requested');
        } catch (GoogleSheetUnreadable) {
        }

        try {
            $reader->csv(self::SPREADSHEET_ID, '0&format=xlsx');
            self::fail('An invalid tab id must not be requested');
        } catch (GoogleSheetUnreadable) {
        }

        self::assertSame([], $requests);
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string> $requests
     */
    private function reader(array $responses, array &$requests = []): GoogleSheetReader
    {
        $client = new MockHttpClient(static function (string $method, string $url) use (&$responses, &$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new GoogleSheetReader($client);
    }
}
