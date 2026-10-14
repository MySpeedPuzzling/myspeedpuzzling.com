<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Mailer\Sns;

use SpeedPuzzling\Web\Exceptions\InvalidSnsMessage;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Proves that a message posted to us really comes from Amazon SNS (AWS "Verifying the signatures of Amazon SNS
 * messages"): the signing certificate is fetched only from an sns.<region>.amazonaws.com HTTPS URL, and the
 * signature covers the message's own fields in the documented order. The topic must be one of ours - anybody can
 * create an SNS topic and point it at this endpoint.
 */
final readonly class SnsMessageVerifier
{
    private const string CERTIFICATE_HOST_PATTERN = '/^sns\.[a-z0-9-]+\.amazonaws\.com$/';

    /** @var list<string> */
    private array $allowedTopicArns;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        string $allowedTopicArns,
    ) {
        $this->allowedTopicArns = array_values(array_filter(array_map(trim(...), explode(',', $allowedTopicArns))));
    }

    /**
     * No topic configured = the endpoint is off
     */
    public function isEnabled(): bool
    {
        return $this->allowedTopicArns !== [];
    }

    /**
     * @return array<string, mixed> the verified message
     *
     * @throws InvalidSnsMessage
     */
    public function verify(string $body): array
    {
        try {
            $message = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidSnsMessage('Not JSON', previous: $exception);
        }

        if (!is_array($message)) {
            throw new InvalidSnsMessage('Not a JSON object');
        }

        /** @var array<string, mixed> $message */
        $topicArn = self::string($message, 'TopicArn');

        if (!in_array($topicArn, $this->allowedTopicArns, true)) {
            throw new InvalidSnsMessage(sprintf('Topic "%s" is not one of ours', $topicArn));
        }

        $algorithm = match (self::string($message, 'SignatureVersion')) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => throw new InvalidSnsMessage('Unknown SignatureVersion'),
        };

        $signature = base64_decode(self::string($message, 'Signature'), true);

        if ($signature === false || $signature === '') {
            throw new InvalidSnsMessage('Signature is not base64');
        }

        $publicKey = openssl_pkey_get_public($this->certificate(self::string($message, 'SigningCertURL')));

        if ($publicKey === false) {
            throw new InvalidSnsMessage('Signing certificate cannot be read');
        }

        if (openssl_verify(self::stringToSign($message), $signature, $publicKey, $algorithm) !== 1) {
            throw new InvalidSnsMessage('Signature does not match');
        }

        return $message;
    }

    /**
     * A SubscribeURL / certificate URL may only ever point at SNS itself
     */
    public static function isSnsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['port'])
            && preg_match(self::CERTIFICATE_HOST_PATTERN, $parts['host'] ?? '') === 1;
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function stringToSign(array $message): string
    {
        $type = self::string($message, 'Type');

        $fields = match ($type) {
            'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => throw new InvalidSnsMessage(sprintf('Unknown message type "%s"', $type)),
        };

        $stringToSign = '';

        foreach ($fields as $field) {
            // Subject is signed only when the message has one
            if ($field === 'Subject' && !isset($message['Subject'])) {
                continue;
            }

            $stringToSign .= $field . "\n" . self::string($message, $field) . "\n";
        }

        return $stringToSign;
    }

    private function certificate(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!self::isSnsUrl($url) || !is_string($path) || !str_ends_with($path, '.pem')) {
            throw new InvalidSnsMessage('Signing certificate URL is not an SNS certificate');
        }

        // A rotated certificate gets a new URL, so the URL is a safe cache key
        return $this->cache->get('sns_certificate_' . hash('sha256', $url), function (ItemInterface $item) use ($url): string {
            $item->expiresAfter(86_400);

            $certificate = $this->httpClient->request('GET', $url, ['timeout' => 5])->getContent();

            if (!str_contains($certificate, 'BEGIN CERTIFICATE')) {
                throw new InvalidSnsMessage('Signing certificate URL returned no certificate');
            }

            return $certificate;
        });
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function string(array $message, string $field): string
    {
        $value = $message[$field] ?? null;

        if (!is_string($value)) {
            throw new InvalidSnsMessage(sprintf('Field "%s" is missing', $field));
        }

        return $value;
    }
}
