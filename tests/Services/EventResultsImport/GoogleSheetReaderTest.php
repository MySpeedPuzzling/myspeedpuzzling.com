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

    public function testATabGoogleRefusesOnceIsAskedForAgain(): void
    {
        // A sheet of 40 tabs: Google sometimes refuses one request (429 / 5xx) - asked once more, it answers
        $requests = [];
        $reader = $this->reader([
            $this->tabsPage(2),
            new MockResponse("Name\nA\n", ['response_headers' => ['content-type' => 'text/csv']]),
            new MockResponse('Too many requests', ['http_code' => 429, 'response_headers' => ['content-type' => 'text/html']]),
            new MockResponse("Name\nB\n", ['response_headers' => ['content-type' => 'text/csv']]),
        ], $requests);

        $snapshot = $reader->snapshot(self::SPREADSHEET_ID);

        self::assertSame([], $snapshot->unreadableTabs);
        self::assertSame(["Name\nA\n", "Name\nB\n"], array_map(static fn ($tab) => $tab->csv, $snapshot->tabs));
        self::assertCount(4, $requests);
    }

    public function testATabRefusedTwiceIsUnreadableAndTheOthersAreRead(): void
    {
        $reader = $this->reader([
            $this->tabsPage(2),
            new MockResponse('Server error', ['http_code' => 503, 'response_headers' => ['content-type' => 'text/html']]),
            new MockResponse("Name\nB\n", ['response_headers' => ['content-type' => 'text/csv']]),
            new MockResponse('Server error', ['http_code' => 503, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        $snapshot = $reader->snapshot(self::SPREADSHEET_ID);

        self::assertCount(1, $snapshot->unreadableTabs);
        self::assertSame('0', $snapshot->unreadableTabs[0]->gid);
        self::assertCount(1, $snapshot->tabs);
    }

    public function testAtMostEightTabsAreAskedForAtOnce(): void
    {
        $inFlight = 0;
        $mostInFlight = 0;
        $page = $this->tabsPage(20);

        $client = new MockHttpClient(static function (string $method, string $url) use ($page, &$inFlight, &$mostInFlight): MockResponse {
            if (str_ends_with($url, '/htmlview')) {
                return $page;
            }

            $inFlight++;
            $mostInFlight = max($mostInFlight, $inFlight);

            // The body is produced when the reader reads it - the request is done then
            return new MockResponse((static function () use (&$inFlight): \Generator {
                $inFlight--;

                yield "Name\nA\n";
            })(), ['response_headers' => ['content-type' => 'text/csv']]);
        });

        $snapshot = (new GoogleSheetReader($client))->snapshot(self::SPREADSHEET_ID);

        self::assertCount(20, $snapshot->tabs);
        self::assertLessThanOrEqual(GoogleSheetReader::TABS_AT_ONCE, $mostInFlight);
    }

    private function tabsPage(int $count): MockResponse
    {
        $page = '<html><script>var items = [];';

        for ($gid = 0; $gid < $count; $gid++) {
            $page .= 'items.push({name: "Tab ' . $gid . '", pageUrl: "x", gid: "' . $gid . '",initialSheet: false});';
        }

        return new MockResponse($page . '</script></html>', ['response_headers' => ['content-type' => 'text/html']]);
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
