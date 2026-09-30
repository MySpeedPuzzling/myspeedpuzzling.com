<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response;
use SpeedPuzzling\Web\Services\SocialLogin\MicrosoftIdTokenVerifier;

/**
 * Real RS256-signed Microsoft id_tokens + the matching JWKS, so tests exercise
 * MicrosoftIdTokenVerifier's signature check for real - only Microsoft's HTTP
 * endpoints are mocked (SocialLoginHttpMock). One throwaway key per test
 * process; tests clear the cached JWKS
 * (MicrosoftIdTokenVerifier::JWKS_CACHE_KEY) so a key cached by an earlier
 * run never gets in the way.
 */
final class MicrosoftIdTokenFactory
{
    public const string CLIENT_ID = '00000000-1111-2222-3333-444444444444';
    public const string KEY_ID = 'ms-test-kid';

    private static null|string $privateKeyPem = null;
    private static null|string $jwks = null;

    /**
     * Queues the token-endpoint answer (with the id_token) followed by the
     * JWKS fetch the verifier performs.
     *
     * @param array<string, mixed> $claims merged over sane defaults
     */
    public static function queueTokenExchange(array $claims): void
    {
        SocialLoginHttpMock::queue(
            self::tokenResponse(self::idToken($claims)),
            self::jwksResponse(),
        );
    }

    public static function tokenResponse(string $idToken): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'ms-at',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'openid profile email',
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));
    }

    public static function jwksResponse(): Response
    {
        self::ensureKey();
        assert(self::$jwks !== null);

        return new Response(200, ['Content-Type' => 'application/json'], self::$jwks);
    }

    /**
     * @param array<string, mixed> $claims merged over sane defaults (consumer
     *        iss/tid, our aud, iat/nbf/exp around now, oid, sub)
     */
    public static function idToken(array $claims, null|string $keyId = self::KEY_ID): string
    {
        self::ensureKey();
        assert(self::$privateKeyPem !== null);

        $claims += self::defaultClaims();

        return JWT::encode(array_filter($claims, static fn (mixed $value): bool => $value !== null), self::$privateKeyPem, 'RS256', $keyId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultClaims(): array
    {
        return [
            'iss' => MicrosoftIdTokenVerifier::ISSUER,
            'tid' => MicrosoftIdTokenVerifier::CONSUMERS_TENANT_ID,
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'oid' => '00000000-0000-0000-aaaa-' . bin2hex(random_bytes(6)),
            'sub' => 'pairwise-' . bin2hex(random_bytes(8)),
            'name' => 'Microsoft User',
        ];
    }

    public static function privateKeyPem(): string
    {
        self::ensureKey();
        assert(self::$privateKeyPem !== null);

        return self::$privateKeyPem;
    }

    private static function ensureKey(): void
    {
        if (self::$privateKeyPem !== null) {
            return;
        }

        $rsaKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        assert($rsaKey !== false);
        openssl_pkey_export($rsaKey, $rsaPem);
        assert(is_string($rsaPem));
        $details = openssl_pkey_get_details($rsaKey);
        assert(is_array($details));
        $rsa = $details['rsa'];
        assert(is_array($rsa) && is_string($rsa['n']) && is_string($rsa['e']));

        self::$privateKeyPem = $rsaPem;
        // Like Microsoft's: no `alg` on the keys
        self::$jwks = json_encode([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'kid' => self::KEY_ID,
                    'n' => self::base64Url($rsa['n']),
                    'e' => self::base64Url($rsa['e']),
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
