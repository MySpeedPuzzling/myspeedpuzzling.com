<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Turnstile;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use SpeedPuzzling\Web\Value\TurnstileVerdict;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Turnstile ("are you a person?") checked on the server: the widget's token goes to siteverify, and only
 * an answer with success, the expected action and our own hostname passes. A token is single use.
 *
 * The keys are the edge's own widget (lily.srv D48 - the bot-blocker's and CrowdSec's challenge pages):
 * `TURNSTILE_SITE_KEY` + `TURNSTILE_SECRET_KEY`. Either empty = off (development, tests that do not set them):
 * nothing is rendered and nothing is checked.
 *
 * Fails closed: siteverify not answering is Unavailable, which the caller refuses like Failed (with a warning, a
 * person should look - a Cloudflare outage stops the newsletter signup).
 */
readonly final class TurnstileVerifier
{
    private const string SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const int TIMEOUT_SECONDS = 10;
    private const int MAX_TOKEN_LENGTH = 2048;

    private string $siteKey;
    private string $secretKey;

    public function __construct(
        #[Autowire(service: 'turnstile.http_client')]
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'TURNSTILE_SITE_KEY')]
        string $siteKey,
        #[Autowire(env: 'TURNSTILE_SECRET_KEY')]
        #[SensitiveParameter]
        string $secretKey,
    ) {
        $this->siteKey = trim($siteKey);
        $this->secretKey = trim($secretKey);
    }

    public function isOn(): bool
    {
        return $this->siteKey !== '' && $this->secretKey !== '';
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function verify(null|string $token, string $expectedAction, string $expectedHostname, null|string $remoteIp): TurnstileVerdict
    {
        if ($token === null || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return TurnstileVerdict::Failed;
        }

        $body = [
            'secret' => $this->secretKey,
            'response' => $token,
        ];

        if ($remoteIp !== null) {
            $body['remoteip'] = $remoteIp;
        }

        try {
            $response = $this->httpClient->request('POST', self::SITEVERIFY_URL, [
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]);

            /** @var array{success?: mixed, action?: mixed, hostname?: mixed, 'error-codes'?: mixed, metadata?: mixed} $result */
            $result = $response->toArray();
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('Turnstile siteverify did not answer', [
                'exception' => $exception,
            ]);

            return TurnstileVerdict::Unavailable;
        }

        // Cloudflare's test keys (local development) answer hostname example.com and no action; a real secret
        // rejects their dummy tokens, so this flag never comes with production keys
        $testingKey = is_array($result['metadata'] ?? null) && ($result['metadata']['result_with_testing_key'] ?? false) === true;

        if (
            ($result['success'] ?? false) !== true
            || (!$testingKey && ($result['action'] ?? null) !== $expectedAction)
            || (!$testingKey && ($result['hostname'] ?? null) !== $expectedHostname)
        ) {
            $this->logger->info('Turnstile check failed', [
                'action' => $expectedAction,
                'error_codes' => $result['error-codes'] ?? null,
                'answered_action' => $result['action'] ?? null,
                'answered_hostname' => $result['hostname'] ?? null,
            ]);

            return TurnstileVerdict::Failed;
        }

        return TurnstileVerdict::Passed;
    }
}
