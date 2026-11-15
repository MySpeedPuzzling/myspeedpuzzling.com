<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Exceptions\GoogleSheetNotFetched;
use SpeedPuzzling\Web\Value\DataImportFileName;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\FetchedGoogleSheet;
use SpeedPuzzling\Web\Value\GoogleSheetFetchFailure;
use SpeedPuzzling\Web\Value\GoogleSheetLink;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches a Google sheet shared with "Anyone with the link" as an .xlsx file - no Google account, no key
 * (docs/features/data-import/google-sheets.md). **What comes back is hostile input over a hostile path:**
 *
 * - the address is built here from the document's id alone (`…/spreadsheets/d/<id>/export?format=xlsx`) - nothing of
 *   what the player pasted is requested;
 * - redirects are followed by hand, at most DataImportLimits::GOOGLE_MAX_REDIRECTS, and **every hop is checked**:
 *   https, no port, no user, and a host of the list below - `docs.google.com` and the download hosts the export
 *   really redirects to (`doc-…-sheets.googleusercontent.com`; seen 2026-11-15: one 307 from docs.google.com there);
 * - every host is resolved first: an address that is not a public one is refused, and the request is pinned to the
 *   address that was checked (no second lookup that could answer differently);
 * - the body is streamed straight into a local file and counted while it arrives - Content-Length is never trusted;
 *   over DataImportLimits::MAX_FILE_BYTES the transfer is cut;
 * - bounded in time: connecting, every wait for bytes, and the whole fetch over all hops together;
 * - what arrives must be a zip (`PK`): Google's sign-in or consent page - sent with a 200 for a sheet that is not
 *   shared - is recognised by its content type and first bytes and never kept.
 *
 * The event results import reads its organizer's sheet by another road (GoogleSheetReader: the tabs' page and CSV per
 * tab, small answers held in memory, redirects followed by the client); the two share the id's pattern and nothing
 * else on purpose - this one takes any player's link.
 */
readonly final class GoogleSheetFile
{
    public const string HOST = 'docs.google.com';
    /**
     * Where the export is downloaded from: `doc-0c-8k-sheets.googleusercontent.com` and its siblings. Not all of
     * `*.googleusercontent.com` - that domain also names Google Cloud customers' machines.
     */
    public const string DOWNLOAD_HOST_PATTERN = '/^doc-[a-z0-9]{1,12}-[a-z0-9]{1,12}-sheets\.googleusercontent\.com\z/';
    /** A redirect there is Google asking to sign in: the sheet is not shared */
    private const string SIGN_IN_HOST = 'accounts.google.com';

    private const string ZIP_MAGIC = "PK\x03\x04";
    /** How much of an answer that is no zip is looked at to tell the sign-in page from anything else */
    private const int LOOKED_AT_BYTES = 65_536;

    public function __construct(
        #[Autowire(service: 'data_import.google_http_client')]
        private HttpClientInterface $client,
        private HostAddresses $hostAddresses,
    ) {
    }

    public static function exportUrl(string $spreadsheetId): string
    {
        return 'https://' . self::HOST . '/spreadsheets/d/' . $spreadsheetId . '/export?format=xlsx';
    }

    public static function isAllowedHost(string $host): bool
    {
        return $host === self::HOST || preg_match(self::DOWNLOAD_HOST_PATTERN, $host) === 1;
    }

    /**
     * @param string $localPath where the file goes; left empty or absent when the fetch fails
     *
     * @throws GoogleSheetNotFetched
     */
    public function fetchTo(string $spreadsheetId, string $localPath, int $maxBytes = DataImportLimits::MAX_FILE_BYTES): FetchedGoogleSheet
    {
        if (GoogleSheetLink::isSpreadsheetId($spreadsheetId) === false) {
            throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::NotFound, 'Not a spreadsheet id.');
        }

        $started = hrtime(true);
        $url = self::exportUrl($spreadsheetId);

        try {
            for ($hop = 0; $hop <= DataImportLimits::GOOGLE_MAX_REDIRECTS; $hop++) {
                $response = $this->request($url, $started);

                try {
                    $status = $response->getStatusCode();
                    $headers = $response->getHeaders(false);

                    if ($status >= 300 && $status < 400) {
                        $url = $this->nextHop($url, $headers['location'][0] ?? null);

                        continue;
                    }

                    if ($status !== 200) {
                        throw self::refusedBy($status);
                    }

                    return $this->receive($response, $headers, $localPath, $maxBytes, $started);
                } finally {
                    self::close($response);
                }
            }
        } catch (ExceptionInterface $e) {
            self::forget($localPath);

            throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::Refused, 'Google did not answer: ' . $e->getMessage(), previous: $e);
        } catch (GoogleSheetNotFetched $e) {
            self::forget($localPath);

            throw $e;
        }

        throw new GoogleSheetNotFetched(
            GoogleSheetFetchFailure::Refused,
            sprintf('More than %d redirects on the way to a Google sheet\'s export.', DataImportLimits::GOOGLE_MAX_REDIRECTS),
            unexpected: true,
        );
    }

    /**
     * @throws GoogleSheetNotFetched
     * @throws ExceptionInterface
     */
    private function request(string $url, int $started): ResponseInterface
    {
        // Lower case already (exportUrl(), nextHop()) - once more, so the lookup, the pin and the check always agree
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $addresses = $this->hostAddresses->of($host);

        if ($addresses === []) {
            throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::Refused, sprintf('The host %s does not resolve.', $host));
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false || IpUtils::isPrivateIp($address)) {
                throw new GoogleSheetNotFetched(
                    GoogleSheetFetchFailure::Refused,
                    sprintf('The host %s resolves to %s - not a public address.', $host, $address),
                    unexpected: true,
                );
            }
        }

        return $this->client->request('GET', $url, [
            // Followed by hand: every hop is checked before it is asked
            'max_redirects' => 0,
            // The address that was checked - no second lookup
            'resolve' => [$host => $addresses[0]],
            'max_connect_duration' => DataImportLimits::GOOGLE_CONNECT_SECONDS,
            'timeout' => DataImportLimits::GOOGLE_IDLE_SECONDS,
            'max_duration' => max(0.1, self::left($started)),
            'buffer' => false,
            'headers' => [
                'User-Agent' => 'MySpeedPuzzling data import (+https://myspeedpuzzling.com)',
                'Accept' => '*/*',
            ],
        ]);
    }

    /**
     * @throws GoogleSheetNotFetched
     */
    private function nextHop(string $from, null|string $location): string
    {
        if ($location === null || $location === '' || strlen($location) > 4000) {
            throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::Refused, 'A redirect without an address.', unexpected: true);
        }

        // Google's redirects are absolute; a path of the same host is taken too, anything else is no address
        if (str_starts_with($location, '/') && str_starts_with($location, '//') === false) {
            $location = 'https://' . parse_url($from, PHP_URL_HOST) . $location;
        }

        $parts = parse_url($location);
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        if (
            is_array($parts) === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::Refused, sprintf('A redirect to %s that is not plain https.', $host === '' ? 'an address without a host' : self::shortened($host)), unexpected: true);
        }

        if ($host === self::SIGN_IN_HOST) {
            throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::NotShared, 'Google asks to sign in - the sheet is not shared with the link.');
        }

        if (self::isAllowedHost($host) === false) {
            throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::Refused, sprintf('A redirect to %s - not a host a Google sheet\'s export comes from.', self::shortened($host)), unexpected: true);
        }

        // Built again around the host that was checked - in lower case, as it is looked up, pinned and asked
        return 'https://' . $host . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @throws GoogleSheetNotFetched
     * @throws ExceptionInterface
     */
    private function receive(ResponseInterface $response, array $headers, string $localPath, int $maxBytes, int $started): FetchedGoogleSheet
    {
        $html = str_starts_with(strtolower(trim($headers['content-type'][0] ?? '')), 'text/html');
        $announced = $headers['content-length'][0] ?? null;

        // Only ever a reason to stop sooner - the count below is what holds
        if ($html === false && $announced !== null && ctype_digit($announced) && (int) $announced > $maxBytes) {
            throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::TooLarge, sprintf('Google announces %s bytes.', $announced));
        }

        $target = fopen($localPath, 'wb');

        if ($target === false) {
            throw new \RuntimeException('The file for a fetched Google sheet can not be opened.');
        }

        $bytes = 0;
        // The first bytes, until they are known to start a zip - or, of an answer that is none, what is looked at
        $head = '';
        // null: not known yet (fewer than four bytes arrived)
        $zip = $html ? false : null;

        try {
            foreach ($this->client->stream($response, DataImportLimits::GOOGLE_IDLE_SECONDS) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::Refused, sprintf('Google sent nothing for %.0f s (%d bytes arrived).', DataImportLimits::GOOGLE_IDLE_SECONDS, $bytes));
                }

                if (self::left($started) <= 0.0) {
                    throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::Refused, sprintf('Fetching took more than %.0f s (%d bytes arrived).', DataImportLimits::GOOGLE_TOTAL_SECONDS, $bytes));
                }

                $content = $chunk->getContent();

                if ($content === '') {
                    continue;
                }

                $bytes += strlen($content);

                if ($zip === null) {
                    $head .= $content;

                    if (strlen($head) < strlen(self::ZIP_MAGIC)) {
                        continue;
                    }

                    $zip = str_starts_with($head, self::ZIP_MAGIC);
                    $content = $zip ? $head : '';
                }

                if ($zip === false) {
                    // Never kept; read on only as far as it is looked at
                    $head = substr($head . $content, 0, self::LOOKED_AT_BYTES);

                    if (strlen($head) >= self::LOOKED_AT_BYTES) {
                        break;
                    }

                    continue;
                }

                if ($bytes > $maxBytes) {
                    throw new GoogleSheetNotFetched(GoogleSheetFetchFailure::TooLarge, sprintf('More than %d bytes arrived.', $maxBytes));
                }

                if (fwrite($target, $content) !== strlen($content)) {
                    throw new \RuntimeException('A fetched Google sheet can not be written to the disk.');
                }
            }
        } finally {
            fclose($target);
        }

        if ($zip !== true) {
            throw self::isSignInPage($head)
                ? new GoogleSheetNotFetched(GoogleSheetFetchFailure::NotShared, 'Google answered with its sign-in page - the sheet is not shared with the link.')
                : new GoogleSheetNotFetched(GoogleSheetFetchFailure::NotASpreadsheet, sprintf('What Google sent is no .xlsx file (%s).', self::shortened($headers['content-type'][0] ?? 'no content type')));
        }

        return new FetchedGoogleSheet($bytes, self::fileName($headers['content-disposition'][0] ?? ''));
    }

    /**
     * Nothing more is read of the answer - whatever is still on its way is dropped with the connection.
     */
    private static function close(ResponseInterface $response): void
    {
        try {
            $response->cancel();
        } catch (ExceptionInterface) {
            // Closing must never hide what the answer was
        }
    }

    private static function refusedBy(int $status): GoogleSheetNotFetched
    {
        return match (true) {
            $status === 401, $status === 403 => new GoogleSheetNotFetched(GoogleSheetFetchFailure::NotShared, sprintf('Google answered %d - the sheet is not shared with the link.', $status)),
            $status === 404, $status === 410 => new GoogleSheetNotFetched(GoogleSheetFetchFailure::NotFound, sprintf('Google answered %d - no such document.', $status)),
            default => new GoogleSheetNotFetched(GoogleSheetFetchFailure::Refused, sprintf('Google answered %d.', $status)),
        };
    }

    private static function isSignInPage(string $head): bool
    {
        // Looks like markup and names the place Google signs people in
        return preg_match('/^\s*</', $head) === 1
            && preg_match('#accounts\.google\.com|ServiceLogin|/signin/|identifierId#i', $head) === 1;
    }

    /**
     * The download's name as Google gives it: `attachment; filename="…"; filename*=UTF-8''…` - the document's title
     * with `.xlsx`. Shown on the player's own pages only.
     */
    private static function fileName(string $contentDisposition): null|string
    {
        $name = null;

        if (preg_match("/filename\\*\\s*=\\s*UTF-8''([^;\\s]+)/i", $contentDisposition, $match) === 1) {
            $name = rawurldecode($match[1]);
        } elseif (preg_match('/filename\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $contentDisposition, $match) === 1) {
            $name = stripslashes($match[1]);
        }

        if ($name === null || mb_check_encoding($name, 'UTF-8') === false) {
            return null;
        }

        $name = DataImportFileName::clean($name);

        if (preg_match('/\.xlsx$/i', $name) === 1) {
            $name = trim(substr($name, 0, -5));
        }

        // The limit holds with the ending
        $name = trim(mb_substr($name, 0, DataImportLimits::FILE_NAME_MAX_LENGTH - 5));

        return $name === '' ? null : $name . '.xlsx';
    }

    private static function left(int $started): float
    {
        return DataImportLimits::GOOGLE_TOTAL_SECONDS - (hrtime(true) - $started) / 1e9;
    }

    private static function shortened(string $text): string
    {
        return mb_substr(mb_scrub($text, 'UTF-8'), 0, 200);
    }

    private static function forget(string $localPath): void
    {
        if (is_file($localPath)) {
            unlink($localPath);
        }
    }
}
