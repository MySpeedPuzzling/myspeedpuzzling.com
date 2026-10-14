<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Value\OnlineCvAnswer;
use SpeedPuzzling\Web\Value\OnlineCvRequest;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The app's side of the CV worker (tools/online-cv/, docs/features/online-competitions/README.md §5.8): one
 * `POST {ONLINE_CV_URL}/check` per finish photo → OnlineCvAnswer.
 * - 200 → the verdict, read defensively (an answer that is not what the worker sends is `failed: invalid_answer`);
 * - 502 (a store did not answer the worker), 503 (busy - its queue is full), 504, no answer within TIMEOUT_SECONDS or
 *   no connection → retry later;
 * - anything else (the photo is gone, not an image, a URL the worker may not fetch, its own error) → failed.
 * A URL whose host is not one of the configured stores' server-side hosts (OnlineMediaStores::serverHosts()) is never
 * sent - the worker refuses any other host too (its ONLINE_CV_ALLOWED_HOSTS, an internal service fetching URLs is an
 * SSRF risk); this side never asks it to.
 */
readonly final class OnlineCvClient
{
    // The worker downloads up to three photos (10 s each at most), queues and checks - well within this
    public const int TIMEOUT_SECONDS = 60;
    private const array RETRY_STATUSES = [502, 503, 504];

    public function __construct(
        private OnlineCvSettings $settings,
        private OnlineMediaStores $stores,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function check(OnlineCvRequest $request): OnlineCvAnswer
    {
        $allowed = $this->stores->serverHosts();

        foreach ($request->urls() as $url) {
            if (!in_array(OnlineMediaStores::hostOf($url), $allowed, true)) {
                return OnlineCvAnswer::failed('url_not_allowed');
            }
        }

        try {
            $response = $this->httpClient->request('POST', $this->settings->url() . '/check', [
                'json' => $request->toArray(),
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            return OnlineCvAnswer::retry('unreachable');
        }

        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];

        if ($status === 200) {
            return self::verdict($data) ?? OnlineCvAnswer::failed('invalid_answer');
        }

        $error = self::errorCode($data, $status);

        return in_array($status, self::RETRY_STATUSES, true) ? OnlineCvAnswer::retry($error) : OnlineCvAnswer::failed($error);
    }

    /**
     * @param array<mixed> $data
     */
    private static function verdict(array $data): null|OnlineCvAnswer
    {
        $verdict = is_string($data['verdict'] ?? null) ? OnlineFinishCheckVerdict::tryFrom($data['verdict']) : null;
        $version = $data['version'] ?? null;

        if ($verdict === null || $verdict === OnlineFinishCheckVerdict::Failed || !is_string($version) || $version === '' || strlen($version) > 32) {
            return null;
        }

        $confidence = is_int($data['confidence'] ?? null) || is_float($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0;
        $image = is_array($data['image'] ?? null) ? $data['image'] : [];
        $method = is_string($data['method'] ?? null) ? substr($data['method'], 0, 32) : 'none';
        $reason = is_string($data['reason'] ?? null) ? mb_substr($data['reason'], 0, 2000) : '';
        $details = [];

        foreach (['details', 'inputs', 'checkMs'] as $key) {
            if (isset($data[$key])) {
                $details[$key] = $data[$key];
            }
        }

        return OnlineCvAnswer::verdict(
            verdict: $verdict,
            confidence: max(0.0, min(1.0, $confidence)),
            gaps: $verdict === OnlineFinishCheckVerdict::Gaps ? self::gaps($data['gaps'] ?? null) : [],
            reason: $reason,
            method: $method,
            version: $version,
            ms: is_int($data['ms'] ?? null) ? $data['ms'] : null,
            imageWidth: is_int($image['width'] ?? null) && $image['width'] > 0 ? $image['width'] : null,
            imageHeight: is_int($image['height'] ?? null) && $image['height'] > 0 ? $image['height'] : null,
            details: $details === [] ? null : $details,
        );
    }

    /**
     * The boxes that are boxes - whole pixels, a size above zero
     *
     * @return list<array{x: int, y: int, w: int, h: int}>
     */
    private static function gaps(mixed $gaps): array
    {
        if (!is_array($gaps)) {
            return [];
        }

        $boxes = [];

        foreach ($gaps as $gap) {
            if (!is_array($gap) || !is_int($gap['x'] ?? null) || !is_int($gap['y'] ?? null) || !is_int($gap['w'] ?? null) || !is_int($gap['h'] ?? null) || $gap['w'] <= 0 || $gap['h'] <= 0) {
                continue;
            }

            $boxes[] = ['x' => $gap['x'], 'y' => $gap['y'], 'w' => $gap['w'], 'h' => $gap['h']];
        }

        return $boxes;
    }

    /**
     * The worker's error code (and which input it was about), else the status
     *
     * @param array<mixed> $data
     */
    private static function errorCode(array $data, int $status): string
    {
        $error = is_string($data['error'] ?? null) && preg_match('/^[a-z_]{1,48}$/', $data['error']) === 1 ? $data['error'] : 'http_' . $status;
        $input = is_string($data['input'] ?? null) && preg_match('/^[a-zA-Z]{1,16}$/', $data['input']) === 1 ? $data['input'] : null;

        return $input === null ? $error : $error . ':' . $input;
    }
}
