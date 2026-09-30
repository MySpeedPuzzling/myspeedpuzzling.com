<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use Firebase\JWT\JWT;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\InvalidMicrosoftIdToken;
use SpeedPuzzling\Web\Exceptions\JwksUnavailable;

/**
 * Verifies the id_token Microsoft's token endpoint returns for a personal
 * account (docs/features/auth-hardening/microsoft-plan.md §2): RS256 against
 * Microsoft's published keys (cached, CachedJwks), audience = our client id,
 * and issuer + tenant = the personal-accounts ("consumers") tenant - so no
 * work/school tenant can ever mint a token we accept (the "nOAuth" class).
 *
 * The token came straight from Microsoft over TLS with our PKCE verifier, so
 * the signature check is defence in depth - but it is what makes the
 * iss/tid assertions mean something.
 *
 * The identity key is `oid` (stable across app registrations - `sub` is
 * pairwise per registration and would change if the registration ever had to
 * be re-created); `sub` must be present too.
 */
final readonly class MicrosoftIdTokenVerifier
{
    public const string JWKS_CACHE_KEY = 'microsoft_consumers_jwks';
    public const string CONSUMERS_TENANT_ID = '9188040d-6c67-4c5b-b112-36a304b66dad';
    public const string ISSUER = 'https://login.microsoftonline.com/' . self::CONSUMERS_TENANT_ID . '/v2.0';
    private const string JWKS_URL = 'https://login.microsoftonline.com/consumers/discovery/v2.0/keys';
    private const int LEEWAY_SECONDS = 60;

    public function __construct(
        private CachedJwks $jwks,
        private ClockInterface $clock,
        private string $microsoftClientId,
    ) {
    }

    /**
     * @return array{oid: string, sub: string, email: null|string, name: null|string}
     *
     * @throws InvalidMicrosoftIdToken
     */
    public function verify(mixed $idToken): array
    {
        if (!is_string($idToken) || $idToken === '') {
            throw new InvalidMicrosoftIdToken('Microsoft token response has no id_token.');
        }

        if ($this->microsoftClientId === '') {
            throw new InvalidMicrosoftIdToken('No Microsoft client id configured.');
        }

        $header = self::unverifiedHeader($idToken);

        // Only RS256 - never `none`, never an HMAC alg keyed with a public key
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw new InvalidMicrosoftIdToken('Unexpected id_token header.');
        }

        try {
            $keys = $this->jwks->rs256Keys(self::JWKS_CACHE_KEY, self::JWKS_URL, $header['kid']);
        } catch (JwksUnavailable $exception) {
            throw new InvalidMicrosoftIdToken('Could not fetch Microsoft keys.', previous: $exception);
        }

        // firebase/php-jwt reads its clock and leeway from statics - set them
        // for this call only (ClockInterface keeps tests deterministic)
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY_SECONDS;
        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            $verified = JWT::decode($idToken, $keys);
        } catch (\Throwable $exception) {
            // Bad signature, unknown key, expired, not yet valid, ...
            throw new InvalidMicrosoftIdToken('Microsoft id_token verification failed.', previous: $exception);
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($verified), associative: true);

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new InvalidMicrosoftIdToken('Microsoft id_token has an unexpected issuer.');
        }

        if (($claims['tid'] ?? null) !== self::CONSUMERS_TENANT_ID) {
            throw new InvalidMicrosoftIdToken('Microsoft id_token is not from the personal accounts tenant.');
        }

        // Microsoft v2.0 tokens carry a single string audience
        if (($claims['aud'] ?? null) !== $this->microsoftClientId) {
            throw new InvalidMicrosoftIdToken('Microsoft id_token was not issued to this client.');
        }

        // `exp` is required: firebase only checks it when present
        if (!is_int($claims['exp'] ?? null)) {
            throw new InvalidMicrosoftIdToken('Microsoft id_token has no expiry.');
        }

        $oid = $claims['oid'] ?? null;
        $sub = $claims['sub'] ?? null;

        if (!is_string($oid) || $oid === '' || !is_string($sub) || $sub === '') {
            throw new InvalidMicrosoftIdToken('Microsoft id_token has no oid or sub.');
        }

        $email = $claims['email'] ?? null;
        $name = $claims['name'] ?? null;

        return [
            'oid' => $oid,
            'sub' => $sub,
            'email' => is_string($email) && trim($email) !== '' ? trim($email) : null,
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function unverifiedHeader(string $jwt): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new InvalidMicrosoftIdToken('Microsoft id_token is not a JWT.');
        }

        $header = json_decode((string) base64_decode(strtr($segments[0], '-_', '+/'), true), associative: true);

        if (!is_array($header)) {
            throw new InvalidMicrosoftIdToken('Microsoft id_token is not a JWT.');
        }

        return $header;
    }
}
