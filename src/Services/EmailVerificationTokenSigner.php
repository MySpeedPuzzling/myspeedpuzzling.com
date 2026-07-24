<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\EmailVerificationTokenExpired;
use SpeedPuzzling\Web\Exceptions\InvalidEmailVerificationToken;
use SpeedPuzzling\Web\Value\EmailVerificationClaim;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless email verification: the token is an HMAC-signed claim binding
 * user_id + email + expiry, so no DB table is needed, links can be validated
 * anonymously, and a link stops working the moment the account email changes.
 */
readonly final class EmailVerificationTokenSigner
{
    public const string LIFETIME = '+24 hours';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
        private ClockInterface $clock,
    ) {
    }

    public function generate(UserAccount $userAccount, DateTimeImmutable $expiresAt): string
    {
        $payload = json_encode([
            'userId' => $userAccount->userId,
            'email' => $userAccount->email,
            'expiresAt' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return self::base64UrlEncode($payload) . '.' . self::base64UrlEncode($this->sign($payload));
    }

    /**
     * @throws InvalidEmailVerificationToken
     * @throws EmailVerificationTokenExpired
     */
    public function parse(#[SensitiveParameter] string $token): EmailVerificationClaim
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            throw new InvalidEmailVerificationToken();
        }

        $payload = self::base64UrlDecode($parts[0]);
        $signature = self::base64UrlDecode($parts[1]);

        if ($payload === null || $signature === null) {
            throw new InvalidEmailVerificationToken();
        }

        if (!hash_equals($this->sign($payload), $signature)) {
            throw new InvalidEmailVerificationToken();
        }

        try {
            /** @var mixed $claims */
            $claims = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidEmailVerificationToken();
        }

        if (!is_array($claims)) {
            throw new InvalidEmailVerificationToken();
        }

        $userId = $claims['userId'] ?? null;
        $email = $claims['email'] ?? null;
        $expiresAt = $claims['expiresAt'] ?? null;

        if (!is_string($userId) || !is_string($email) || !is_int($expiresAt)) {
            throw new InvalidEmailVerificationToken();
        }

        // Only after the signature check - an attacker must not learn anything from a forged expiry
        if ($expiresAt <= $this->clock->now()->getTimestamp()) {
            throw new EmailVerificationTokenExpired();
        }

        return new EmailVerificationClaim($userId, $email);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, binary: true);
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
