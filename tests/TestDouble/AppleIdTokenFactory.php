<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response;

/**
 * Real RS256-signed Apple id_tokens + the matching JWKS, so tests exercise the
 * library's signature verification for real - only Apple's HTTP endpoints are
 * mocked (SocialLoginHttpMock).
 */
final class AppleIdTokenFactory
{
    public const string CLIENT_ID = 'com.myspeedpuzzling.test';

    /**
     * Queues the token-endpoint answer followed by the JWKS fetch that
     * AppleAccessToken performs to verify the id_token.
     *
     * @param array<string, mixed> $claims merged over sane defaults (iss, aud, iat, exp)
     */
    public static function queueTokenExchange(array $claims): void
    {
        [$jwks, $idToken] = self::signedIdToken($claims + [
            'iss' => 'https://appleid.apple.com',
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'exp' => time() + 600,
        ]);

        SocialLoginHttpMock::queue(
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'apple-at',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'apple-rt',
                'id_token' => $idToken,
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], $jwks),
        );
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array{0: string, 1: string} JWKS body + RS256-signed id_token
     */
    public static function signedIdToken(array $claims): array
    {
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

        $jwks = json_encode([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => 'test-kid',
                    'n' => self::base64Url($rsa['n']),
                    'e' => self::base64Url($rsa['e']),
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        return [$jwks, JWT::encode($claims, $rsaPem, 'RS256', 'test-kid')];
    }

    /**
     * A throwaway EC P-256 key: the provider signs its ES256 client-secret JWT
     * with it for real.
     */
    public static function clientSecretKeyPem(): string
    {
        $ecKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        assert($ecKey !== false);
        openssl_pkey_export($ecKey, $ecPem);
        assert(is_string($ecPem));

        return $ecPem;
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
