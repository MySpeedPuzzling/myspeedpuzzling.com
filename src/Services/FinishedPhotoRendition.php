<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Exceptions\FinishedPhotoUnavailable;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The image pipeline's copy of a finished-puzzle photo (GET /internal-api/puzzles/{id}/finished-photos/{fingerprint},
 * docs/features/insights/finished-photos.md): the `puzzle_large` rendition from the internal image proxy - at most
 * 1200 px, JPEG, EXIF orientation applied and **all metadata stripped** (no GPS ever leaves the app), ~0.3 MB instead
 * of the 2-3 MB original - read through the app so the stored path (which names the player) never does. The same
 * rendition the site shows, so it is usually cached already.
 */
readonly final class FinishedPhotoRendition
{
    public const string PRESET = 'puzzle_large';

    // A 1200 px JPEG is far below this - more means something else answered
    public const int MAX_BYTES = 10_000_000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $nginxProxyInternalUrl,
    ) {
    }

    /**
     * The JPEG bytes, or null when the proxy has no such picture (404).
     *
     * @throws FinishedPhotoUnavailable the proxy did not answer with a picture
     */
    public function bytes(string $path): null|string
    {
        $url = sprintf('%s/preset:%s/plain/%s', rtrim($this->nginxProxyInternalUrl, '/'), self::PRESET, ltrim($path, '/'));

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'image/jpeg'],
                'timeout' => 30,
                'max_duration' => 60,
            ]);
            $status = $response->getStatusCode();

            if ($status === 404) {
                return null;
            }

            if ($status !== 200) {
                throw new FinishedPhotoUnavailable(sprintf('The image proxy answered %d.', $status));
            }

            $type = $response->getHeaders(false)['content-type'][0] ?? '';

            if (str_starts_with($type, 'image/') === false) {
                throw new FinishedPhotoUnavailable('The image proxy did not answer with a picture.');
            }

            $bytes = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new FinishedPhotoUnavailable('The image proxy could not be reached.', $exception);
        }

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new FinishedPhotoUnavailable('The image proxy answered no usable picture.');
        }

        return $bytes;
    }
}
