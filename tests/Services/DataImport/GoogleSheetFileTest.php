<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\GoogleSheetNotFetched;
use SpeedPuzzling\Web\Services\DataImport\GoogleSheetFile;
use SpeedPuzzling\Web\Tests\TestDouble\FixedHostAddresses;
use SpeedPuzzling\Web\Tests\TestDouble\GoogleSheetHttpMock;
use SpeedPuzzling\Web\Value\GoogleSheetFetchFailure;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * docs/features/data-import/google-sheets.md - Google's answers are recorded shapes, never the network; every id is
 * made up.
 */
final class GoogleSheetFileTest extends TestCase
{
    private const string ID = '1MadeUpDocumentId_0123456789-abcdefghijklmnop';
    private const string XLSX = "PK\x03\x04 the rest of a made-up workbook";
    private const string XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private string $path;

    protected function setUp(): void
    {
        GoogleSheetHttpMock::reset();
        FixedHostAddresses::reset();
        $this->path = (string) tempnam(sys_get_temp_dir(), 'msp-google-test');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        GoogleSheetHttpMock::reset();
        FixedHostAddresses::reset();
    }

    public function testAPublicSheetArrivesAsAFileWithItsTitle(): void
    {
        GoogleSheetHttpMock::answerWithExport(self::ID, self::XLSX, 'attachment; filename="Mypuzzles2024.xlsx"; filename*=UTF-8\'\'My%20puzzles%20%E2%80%93%202024.xlsx');

        $fetched = $this->file()->fetchTo(self::ID, $this->path);

        self::assertSame(strlen(self::XLSX), $fetched->bytes);
        self::assertSame('My puzzles – 2024.xlsx', $fetched->fileName);
        self::assertSame(self::XLSX, file_get_contents($this->path));

        // The address is ours, built from the id; the redirect was followed by hand, each hop pinned to the address
        // that was checked
        $requests = GoogleSheetHttpMock::requests();
        self::assertSame(
            ['https://docs.google.com/spreadsheets/d/' . self::ID . '/export?format=xlsx', GoogleSheetHttpMock::downloadUrl(self::ID)],
            array_column($requests, 'url'),
        );
        self::assertSame([0, 0], array_column($requests, 'maxRedirects'));
        self::assertSame(['docs.google.com' => FixedHostAddresses::PUBLIC_ADDRESS], $requests[0]['resolve']);
        self::assertSame(['doc-0a-1b-sheets.googleusercontent.com' => FixedHostAddresses::PUBLIC_ADDRESS], $requests[1]['resolve']);
    }

    public function testATitleGoogleDoesNotGiveIsNoName(): void
    {
        GoogleSheetHttpMock::answerWithExport(self::ID, self::XLSX);

        self::assertNull($this->file()->fetchTo(self::ID, $this->path)->fileName);
    }

    public function testATitleIsCleanedLikeAnUploadedFilesName(): void
    {
        GoogleSheetHttpMock::answerWithExport(self::ID, self::XLSX, 'attachment; filename="..%2F..%2Fetc%2Fpasswd.xlsx"; filename*=UTF-8\'\'..%2F..%5Cetc%0D%0A' . str_repeat('a', 400) . '.xlsx');

        $name = $this->file()->fetchTo(self::ID, $this->path)->fileName;

        self::assertNotNull($name);
        self::assertStringNotContainsString('/', $name);
        self::assertStringNotContainsString('\\', $name);
        self::assertStringNotContainsString("\n", $name);
        self::assertStringEndsWith('.xlsx', $name);
        self::assertLessThanOrEqual(200, mb_strlen($name));
    }

    /**
     * @param list<MockResponse> $answers
     */
    #[DataProvider('provideRefusals')]
    public function testWhatGoogleAnswersIsToldApart(array $answers, GoogleSheetFetchFailure $failure, bool $unexpected = false): void
    {
        GoogleSheetHttpMock::answer(...$answers);

        try {
            $this->file()->fetchTo(self::ID, $this->path);
            self::fail('The sheet was fetched.');
        } catch (GoogleSheetNotFetched $e) {
            self::assertSame($failure, $e->failure, $e->getMessage());
            self::assertSame($unexpected, $e->unexpected, $e->getMessage());
        }

        // Nothing of an answer that was refused stays on the disk
        self::assertFalse(is_file($this->path) && filesize($this->path) > 0);
    }

    /**
     * @return iterable<string, array{0: list<MockResponse>, 1: GoogleSheetFetchFailure, 2?: bool}>
     */
    public static function provideRefusals(): iterable
    {
        $html = ['response_headers' => ['content-type' => 'text/html; charset=utf-8']];
        $signIn = '<!DOCTYPE html><html><head><title>Sign in - Google Accounts</title></head><body><form action="https://accounts.google.com/v3/signin/identifier">' . str_repeat(' ', 5000) . '</form></body></html>';
        $download = GoogleSheetHttpMock::redirect(GoogleSheetHttpMock::downloadUrl(self::ID));

        yield 'not shared: the sign-in page with a 200' => [[new MockResponse($signIn, $html)], GoogleSheetFetchFailure::NotShared];
        yield 'not shared: the sign-in page with a 200 and a lying content type' => [[new MockResponse($signIn, ['response_headers' => ['content-type' => self::XLSX_TYPE]])], GoogleSheetFetchFailure::NotShared];
        yield 'not shared: 401' => [[new MockResponse($signIn, ['http_code' => 401] + $html)], GoogleSheetFetchFailure::NotShared];
        yield 'not shared: 403' => [[new MockResponse('', ['http_code' => 403])], GoogleSheetFetchFailure::NotShared];
        yield 'not shared: sent to the sign-in' => [[GoogleSheetHttpMock::redirect('https://accounts.google.com/ServiceLogin?continue=https%3A%2F%2Fdocs.google.com%2F', 302)], GoogleSheetFetchFailure::NotShared];
        yield 'no such document: 404' => [[new MockResponse('<html>Sorry, the file you have requested does not exist.</html>', ['http_code' => 404] + $html)], GoogleSheetFetchFailure::NotFound];
        yield 'no such document: 410' => [[new MockResponse('', ['http_code' => 410])], GoogleSheetFetchFailure::NotFound];
        yield 'Google refuses: 500' => [[new MockResponse('', ['http_code' => 500])], GoogleSheetFetchFailure::Refused];
        yield 'Google refuses: 429' => [[new MockResponse('', ['http_code' => 429])], GoogleSheetFetchFailure::Refused];
        yield 'Google refuses: 400' => [[new MockResponse('', ['http_code' => 400])], GoogleSheetFetchFailure::Refused];
        yield 'Google refuses after the redirect' => [[$download, new MockResponse('', ['http_code' => 503])], GoogleSheetFetchFailure::Refused];
        yield 'no answer' => [[new MockResponse('', ['error' => 'Connection refused'])], GoogleSheetFetchFailure::Refused];
        yield 'no bytes for too long' => [[$download, new MockResponse(['PK', '', "\x03\x04"], ['response_headers' => ['content-type' => self::XLSX_TYPE]])], GoogleSheetFetchFailure::Refused];
        yield 'a page that is no sign-in page' => [[new MockResponse('<html><body>An error page of some kind</body></html>', $html)], GoogleSheetFetchFailure::NotASpreadsheet];
        yield 'markup under a spreadsheet\'s content type' => [[$download, new MockResponse('<html><body>Too big to export</body></html>', ['response_headers' => ['content-type' => self::XLSX_TYPE]])], GoogleSheetFetchFailure::NotASpreadsheet];
        yield 'a zip under an HTML content type' => [[new MockResponse(self::XLSX, $html)], GoogleSheetFetchFailure::NotASpreadsheet];
        yield 'bytes that are no zip' => [[$download, new MockResponse("puzzle,time\nMade up,1:00:00\n", ['response_headers' => ['content-type' => 'text/csv']])], GoogleSheetFetchFailure::NotASpreadsheet];
        yield 'nothing at all' => [[$download, new MockResponse('', ['response_headers' => ['content-type' => self::XLSX_TYPE]])], GoogleSheetFetchFailure::NotASpreadsheet];
        yield 'a redirect to another host' => [[GoogleSheetHttpMock::redirect('https://example.com/export.xlsx')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect to a host that only looks like the download host' => [[GoogleSheetHttpMock::redirect('https://doc-0a-1b-sheets.googleusercontent.com.example.com/export')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect to another machine of googleusercontent.com' => [[GoogleSheetHttpMock::redirect('https://10.0.0.5.bc.googleusercontent.com/export')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect to a private address' => [[GoogleSheetHttpMock::redirect('https://10.0.0.5/export.xlsx')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect to the cloud\'s metadata address' => [[GoogleSheetHttpMock::redirect('http://169.254.169.254/latest/meta-data/')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect to this machine' => [[GoogleSheetHttpMock::redirect('https://localhost/export.xlsx')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect without https' => [[GoogleSheetHttpMock::redirect('http://doc-0a-1b-sheets.googleusercontent.com/export')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect to another port' => [[GoogleSheetHttpMock::redirect('https://doc-0a-1b-sheets.googleusercontent.com:8443/export')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect with a user' => [[GoogleSheetHttpMock::redirect('https://docs.google.com@example.com/export')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect to another scheme' => [[GoogleSheetHttpMock::redirect('file:///etc/passwd')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect that names no host' => [[GoogleSheetHttpMock::redirect('//example.com/export')], GoogleSheetFetchFailure::Refused, true];
        yield 'a redirect without an address' => [[new MockResponse('', ['http_code' => 302])], GoogleSheetFetchFailure::Refused, true];
        yield 'redirect after redirect' => [array_fill(0, 6, $download), GoogleSheetFetchFailure::Refused, true];
    }

    public function testFiveRedirectsAreFollowedAndNoMore(): void
    {
        GoogleSheetHttpMock::answer(
            ...array_fill(0, 5, GoogleSheetHttpMock::redirect(GoogleSheetHttpMock::downloadUrl(self::ID))),
        );
        GoogleSheetHttpMock::answer(new MockResponse(self::XLSX, ['response_headers' => ['content-type' => self::XLSX_TYPE]]));

        self::assertSame(strlen(self::XLSX), $this->file()->fetchTo(self::ID, $this->path)->bytes);
        self::assertCount(6, GoogleSheetHttpMock::requests());
    }

    public function testAnAllowedHostInUpperCaseIsAskedInLowerCase(): void
    {
        GoogleSheetHttpMock::answer(
            GoogleSheetHttpMock::redirect('https://DOC-0A-1B-Sheets.GoogleUserContent.COM/export/made-up?format=xlsx'),
            new MockResponse(self::XLSX, ['response_headers' => ['content-type' => self::XLSX_TYPE]]),
        );

        self::assertSame(strlen(self::XLSX), $this->file()->fetchTo(self::ID, $this->path)->bytes);

        // Looked up, pinned and asked under the one name that was checked
        $second = GoogleSheetHttpMock::requests()[1];
        self::assertSame('https://doc-0a-1b-sheets.googleusercontent.com/export/made-up?format=xlsx', $second['url']);
        self::assertSame(['doc-0a-1b-sheets.googleusercontent.com' => FixedHostAddresses::PUBLIC_ADDRESS], $second['resolve']);
    }

    public function testARefusedRedirectsAddressIsNotInTheMessage(): void
    {
        GoogleSheetHttpMock::answer(GoogleSheetHttpMock::redirect('http://doc-0a-1b-sheets.googleusercontent.com/export/made-up?token=secret-of-the-download'));

        try {
            $this->file()->fetchTo(self::ID, $this->path);
            self::fail('The sheet was fetched.');
        } catch (GoogleSheetNotFetched $e) {
            self::assertStringContainsString('doc-0a-1b-sheets.googleusercontent.com', $e->getMessage());
            self::assertStringNotContainsString('secret-of-the-download', $e->getMessage());
            self::assertStringNotContainsString('/export', $e->getMessage());
        }
    }

    public function testADownloadHostWithALineBreakAfterItIsNotOnTheList(): void
    {
        self::assertFalse(GoogleSheetFile::isAllowedHost("doc-0a-1b-sheets.googleusercontent.com\n"));
        self::assertFalse(GoogleSheetFile::isAllowedHost("docs.google.com\n"));
    }

    public function testARefusedRedirectIsNeverRequested(): void
    {
        GoogleSheetHttpMock::answer(GoogleSheetHttpMock::redirect('https://example.com/export.xlsx'), new MockResponse(self::XLSX));

        try {
            $this->file()->fetchTo(self::ID, $this->path);
            self::fail('The sheet was fetched.');
        } catch (GoogleSheetNotFetched) {
        }

        self::assertCount(1, GoogleSheetHttpMock::requests());
    }

    public function testAHostOfTheListThatResolvesToAPrivateAddressIsNeverRequested(): void
    {
        // Somebody in the path answers the lookup of Google's download host with an address of our own network
        FixedHostAddresses::resolve('doc-0a-1b-sheets.googleusercontent.com', [FixedHostAddresses::PUBLIC_ADDRESS, '10.0.0.5']);
        GoogleSheetHttpMock::answerWithExport(self::ID, self::XLSX);

        try {
            $this->file()->fetchTo(self::ID, $this->path);
            self::fail('The sheet was fetched.');
        } catch (GoogleSheetNotFetched $e) {
            self::assertSame(GoogleSheetFetchFailure::Refused, $e->failure);
            self::assertTrue($e->unexpected);
        }

        self::assertCount(1, GoogleSheetHttpMock::requests(), 'Only docs.google.com was asked');

        foreach (['127.0.0.1', '169.254.169.254', '192.168.1.10', '172.16.0.1', '0.0.0.0'] as $address) {
            GoogleSheetHttpMock::reset();
            FixedHostAddresses::resolve('docs.google.com', [$address]);
            GoogleSheetHttpMock::answerWithExport(self::ID, self::XLSX);

            try {
                $this->file()->fetchTo(self::ID, $this->path);
                self::fail('The sheet was fetched from ' . $address);
            } catch (GoogleSheetNotFetched $e) {
                self::assertTrue($e->unexpected, $address);
            }

            self::assertSame([], GoogleSheetHttpMock::requests(), $address);
        }
    }

    public function testAHostThatDoesNotResolveIsNotAsked(): void
    {
        FixedHostAddresses::resolve('docs.google.com', []);

        try {
            $this->file()->fetchTo(self::ID, $this->path);
            self::fail('The sheet was fetched.');
        } catch (GoogleSheetNotFetched $e) {
            self::assertSame(GoogleSheetFetchFailure::Refused, $e->failure);
            self::assertFalse($e->unexpected);
        }

        self::assertSame([], GoogleSheetHttpMock::requests());
    }

    public function testTheTransferIsCutWhenMoreArrivesThanAnImportTakes(): void
    {
        // No Content-Length (chunked), and far more on its way than an import takes
        $body = (static function (): \Generator {
            yield "PK\x03\x04";

            for ($i = 0; $i < 100; $i++) {
                yield str_repeat('a', 1000);
            }
        })();

        GoogleSheetHttpMock::answer(
            GoogleSheetHttpMock::redirect(GoogleSheetHttpMock::downloadUrl(self::ID)),
            new MockResponse($body, ['response_headers' => ['content-type' => self::XLSX_TYPE]]),
        );

        try {
            $this->file()->fetchTo(self::ID, $this->path, maxBytes: 20_000);
            self::fail('The sheet was fetched.');
        } catch (GoogleSheetNotFetched $e) {
            self::assertSame(GoogleSheetFetchFailure::TooLarge, $e->failure);
        }

        self::assertFileDoesNotExist($this->path);
    }

    public function testAnAnnouncedSizeOverTheLimitEndsItAtOnce(): void
    {
        $content = "PK\x03\x04" . str_repeat('a', 4996);
        GoogleSheetHttpMock::answer(new MockResponse($content, ['response_headers' => ['content-type' => self::XLSX_TYPE, 'content-length' => '5000']]));

        try {
            $this->file()->fetchTo(self::ID, $this->path, maxBytes: 1000);
            self::fail('The sheet was fetched.');
        } catch (GoogleSheetNotFetched $e) {
            self::assertSame(GoogleSheetFetchFailure::TooLarge, $e->failure);
            self::assertStringContainsString('announces', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->path);
    }

    public function testAFileOfExactlyTheLimitIsTaken(): void
    {
        $content = "PK\x03\x04" . str_repeat('a', 996);
        GoogleSheetHttpMock::answerWithExport(self::ID, $content);

        self::assertSame(1000, $this->file()->fetchTo(self::ID, $this->path, maxBytes: 1000)->bytes);
        self::assertSame($content, file_get_contents($this->path));
    }

    public function testTheFirstBytesMayArriveOneByOne(): void
    {
        GoogleSheetHttpMock::answer(new MockResponse(['P', 'K', "\x03", "\x04", ' and', ' the rest'], ['response_headers' => ['content-type' => self::XLSX_TYPE]]));

        self::assertSame(17, $this->file()->fetchTo(self::ID, $this->path)->bytes);
        self::assertSame("PK\x03\x04 and the rest", file_get_contents($this->path));
    }

    public function testAPageLongerThanWhatIsLookedAtIsStillToldApart(): void
    {
        // The sign-in page's marks stand at its start; what follows is never kept
        $page = '<html><a href="https://accounts.google.com/ServiceLogin">Sign in</a>' . str_repeat('<p>filler</p>', 20_000) . '</html>';
        GoogleSheetHttpMock::answer(new MockResponse(str_split($page, 8000), ['response_headers' => ['content-type' => 'text/html']]));

        try {
            $this->file()->fetchTo(self::ID, $this->path);
            self::fail('The sheet was fetched.');
        } catch (GoogleSheetNotFetched $e) {
            self::assertSame(GoogleSheetFetchFailure::NotShared, $e->failure);
        }

        self::assertFileDoesNotExist($this->path);
    }

    public function testSomethingThatIsNoDocumentIdIsNeverAskedFor(): void
    {
        foreach (['', 'short', '../../../etc/passwd_0123456789012345', self::ID . '/export?format=csv&x='] as $id) {
            try {
                $this->file()->fetchTo($id, $this->path);
                self::fail('The sheet was fetched.');
            } catch (GoogleSheetNotFetched $e) {
                self::assertSame(GoogleSheetFetchFailure::NotFound, $e->failure);
            }
        }

        self::assertSame([], GoogleSheetHttpMock::requests());
    }

    public function testTheHostsOfTheList(): void
    {
        self::assertTrue(GoogleSheetFile::isAllowedHost('docs.google.com'));
        self::assertTrue(GoogleSheetFile::isAllowedHost('doc-08-4o-sheets.googleusercontent.com'));
        self::assertFalse(GoogleSheetFile::isAllowedHost('googleusercontent.com'));
        self::assertFalse(GoogleSheetFile::isAllowedHost('lh3.googleusercontent.com'));
        self::assertFalse(GoogleSheetFile::isAllowedHost('accounts.google.com'));
        self::assertFalse(GoogleSheetFile::isAllowedHost('drive.google.com'));
        self::assertFalse(GoogleSheetFile::isAllowedHost('docs.google.com.example.com'));
    }

    private function file(): GoogleSheetFile
    {
        return new GoogleSheetFile(GoogleSheetHttpMock::client(), new FixedHostAddresses());
    }
}
