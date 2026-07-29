<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\NewsletterConfirmTokenExpired;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SpeedPuzzling\Web\Value\NewsletterConfirmClaim;
use SpeedPuzzling\Web\Value\NewsletterPreferencesClaim;
use SpeedPuzzling\Web\Value\NewsletterUnsubscribeClaim;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless newsletter tokens: an HMAC-signed claim binding audience
 * (player/guest) + id + e-mail, so links can be validated anonymously and stop
 * working the moment the address changes.
 *
 * Unsubscribe tokens deliberately carry no expiry (an unsubscribe link in an
 * old newsletter must keep working forever) and are deterministic, so the
 * Listmonk sync can diff subscriber attributes without churn. Confirm tokens
 * (double opt-in) do expire.
 */
readonly final class NewsletterTokenSigner
{
    public const int CONFIRM_LIFETIME_HOURS = 48;

    private const string TYPE_UNSUBSCRIBE = 'unsubscribe';
    private const string TYPE_CONFIRM = 'confirm';
    private const string TYPE_PREFERENCES = 'preferences';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
        private ClockInterface $clock,
    ) {
    }

    public function generateUnsubscribeToken(NewsletterAudience $audience, string $id, string $email): string
    {
        return $this->encode([
            'type' => self::TYPE_UNSUBSCRIBE,
            'audience' => $audience->value,
            'id' => $id,
            'email' => mb_strtolower(trim($email)),
        ]);
    }

    public function generateConfirmToken(NewsletterAudience $audience, string $id, string $email): string
    {
        $expiresAt = $this->clock->now()->modify('+' . self::CONFIRM_LIFETIME_HOURS . ' hours');

        return $this->encode([
            'type' => self::TYPE_CONFIRM,
            'audience' => $audience->value,
            'id' => $id,
            'email' => mb_strtolower(trim($email)),
            'expiresAt' => $expiresAt->getTimestamp(),
        ]);
    }

    /**
     * Preferences tokens open the standalone e-mail preferences page. Like
     * unsubscribe tokens they never expire (the link lives in every sent
     * e-mail) and are deterministic for Listmonk attribute diffing.
     */
    public function generatePreferencesToken(NewsletterAudience $audience, string $id, string $email): string
    {
        return $this->encode([
            'type' => self::TYPE_PREFERENCES,
            'audience' => $audience->value,
            'id' => $id,
            'email' => mb_strtolower(trim($email)),
        ]);
    }

    /**
     * @throws InvalidNewsletterToken
     */
    public function parsePreferencesToken(#[SensitiveParameter] string $token): NewsletterPreferencesClaim
    {
        $claims = $this->decode($token);

        if (($claims['type'] ?? null) !== self::TYPE_PREFERENCES) {
            throw new InvalidNewsletterToken();
        }

        [$audience, $id, $email] = $this->readCommonClaims($claims);

        return new NewsletterPreferencesClaim($audience, $id, $email);
    }

    /**
     * @throws InvalidNewsletterToken
     */
    public function parseUnsubscribeToken(#[SensitiveParameter] string $token): NewsletterUnsubscribeClaim
    {
        $claims = $this->decode($token);

        if (($claims['type'] ?? null) !== self::TYPE_UNSUBSCRIBE) {
            throw new InvalidNewsletterToken();
        }

        [$audience, $id, $email] = $this->readCommonClaims($claims);

        return new NewsletterUnsubscribeClaim($audience, $id, $email);
    }

    /**
     * @throws InvalidNewsletterToken
     * @throws NewsletterConfirmTokenExpired
     */
    public function parseConfirmToken(#[SensitiveParameter] string $token): NewsletterConfirmClaim
    {
        $claims = $this->decode($token);

        if (($claims['type'] ?? null) !== self::TYPE_CONFIRM) {
            throw new InvalidNewsletterToken();
        }

        [$audience, $id, $email] = $this->readCommonClaims($claims);
        $expiresAt = $claims['expiresAt'] ?? null;

        if (!is_int($expiresAt)) {
            throw new InvalidNewsletterToken();
        }

        // Only after the signature check - an attacker must not learn anything from a forged expiry
        if ($expiresAt <= $this->clock->now()->getTimestamp()) {
            throw new NewsletterConfirmTokenExpired();
        }

        return new NewsletterConfirmClaim($audience, $id, $email);
    }

    /**
     * @param array<mixed> $claims
     * @return array{NewsletterAudience, string, string}
     *
     * @throws InvalidNewsletterToken
     */
    private function readCommonClaims(array $claims): array
    {
        $audience = $claims['audience'] ?? null;
        $id = $claims['id'] ?? null;
        $email = $claims['email'] ?? null;

        if (!is_string($audience) || !is_string($id) || !is_string($email)) {
            throw new InvalidNewsletterToken();
        }

        $audienceEnum = NewsletterAudience::tryFrom($audience);

        if ($audienceEnum === null) {
            throw new InvalidNewsletterToken();
        }

        return [$audienceEnum, $id, $email];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function encode(array $claims): string
    {
        $payload = json_encode($claims, JSON_THROW_ON_ERROR);

        return self::base64UrlEncode($payload) . '.' . self::base64UrlEncode($this->sign($payload));
    }

    /**
     * @return array<mixed>
     *
     * @throws InvalidNewsletterToken
     */
    private function decode(#[SensitiveParameter] string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            throw new InvalidNewsletterToken();
        }

        $payload = self::base64UrlDecode($parts[0]);
        $signature = self::base64UrlDecode($parts[1]);

        if ($payload === null || $signature === null) {
            throw new InvalidNewsletterToken();
        }

        if (!hash_equals($this->sign($payload), $signature)) {
            throw new InvalidNewsletterToken();
        }

        try {
            /** @var mixed $claims */
            $claims = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidNewsletterToken();
        }

        if (!is_array($claims)) {
            throw new InvalidNewsletterToken();
        }

        return $claims;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'newsletter.' . $payload, $this->secret, binary: true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): null|string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }
}
