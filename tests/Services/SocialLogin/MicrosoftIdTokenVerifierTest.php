<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SocialLogin;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidMicrosoftIdToken;
use SpeedPuzzling\Web\Services\SocialLogin\CachedJwks;
use SpeedPuzzling\Web\Services\SocialLogin\MicrosoftIdTokenVerifier;
use SpeedPuzzling\Web\Tests\TestDouble\MicrosoftIdTokenFactory;
use SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Microsoft id_tokens (microsoft-plan.md §2): RS256 against the consumers
 * JWKS, our audience, the consumers issuer + tenant, a live expiry, and both
 * `oid` and `sub` present - anything else signs nobody in.
 */
final class MicrosoftIdTokenVerifierTest extends TestCase
{
    private MockClock $clock;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        SocialLoginHttpMock::reset();
        $this->clock = new MockClock();
        $this->cache = new ArrayAdapter();
    }

    public function testValidTokenReturnsItsClaims(): void
    {
        SocialLoginHttpMock::queue(MicrosoftIdTokenFactory::jwksResponse());

        $claims = $this->verifier()->verify($this->token([
            'oid' => 'the-oid',
            'sub' => 'the-sub',
            'email' => ' someone@outlook.com ',
            'name' => 'Some One',
        ]));

        self::assertSame(['oid' => 'the-oid', 'sub' => 'the-sub', 'email' => 'someone@outlook.com', 'name' => 'Some One'], $claims);
    }

    public function testMissingEmailAndNameAreNull(): void
    {
        SocialLoginHttpMock::queue(MicrosoftIdTokenFactory::jwksResponse());

        $claims = $this->verifier()->verify($this->token(['name' => null]));

        self::assertNull($claims['email']);
        self::assertNull($claims['name']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rejectedClaims(): iterable
    {
        yield 'another app' => [['aud' => 'someone-elses-client-id']];
        yield 'audience list' => [['aud' => [MicrosoftIdTokenFactory::CLIENT_ID, 'other']]];
        yield 'work tenant issuer' => [['iss' => 'https://login.microsoftonline.com/72f988bf-86f1-41af-91ab-2d7cd011db47/v2.0']];
        yield 'v1 issuer' => [['iss' => 'https://sts.windows.net/' . MicrosoftIdTokenVerifier::CONSUMERS_TENANT_ID . '/']];
        yield 'work tenant id' => [['tid' => '72f988bf-86f1-41af-91ab-2d7cd011db47']];
        yield 'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200, 'nbf' => time() - 7200]];
        yield 'not yet valid' => [['nbf' => time() + 3600, 'iat' => time() + 3600]];
        yield 'no expiry' => [['exp' => null]];
        yield 'no oid' => [['oid' => null]];
        yield 'empty oid' => [['oid' => '']];
        yield 'no sub' => [['sub' => null]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('rejectedClaims')]
    public function testRejects(array $claims): void
    {
        SocialLoginHttpMock::queue(MicrosoftIdTokenFactory::jwksResponse());

        $this->expectException(InvalidMicrosoftIdToken::class);

        $this->verifier()->verify($this->token($claims));
    }

    public function testExpiryHasOneMinuteLeeway(): void
    {
        SocialLoginHttpMock::queue(MicrosoftIdTokenFactory::jwksResponse());

        $claims = $this->verifier()->verify($this->token(['exp' => time() - 30]));

        self::assertNotSame('', $claims['oid']);
    }

    public function testTokenSignedWithAnotherKeyIsRejected(): void
    {
        SocialLoginHttpMock::queue(MicrosoftIdTokenFactory::jwksResponse());

        $otherKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        assert($otherKey !== false);
        openssl_pkey_export($otherKey, $otherPem);
        assert(is_string($otherPem));
        $forged = JWT::encode(MicrosoftIdTokenFactory::defaultClaims(), $otherPem, 'RS256', MicrosoftIdTokenFactory::KEY_ID);

        $this->expectException(InvalidMicrosoftIdToken::class);

        $this->verifier()->verify($forged);
    }

    public function testAlgNoneIsRejectedWithoutFetchingKeys(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT","kid":"ms-test-kid"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode((string) json_encode(MicrosoftIdTokenFactory::defaultClaims())), '+/', '-_'), '=');

        try {
            $this->verifier()->verify("{$header}.{$payload}.");
            self::fail('alg none must be rejected');
        } catch (InvalidMicrosoftIdToken) {
            self::assertSame([], SocialLoginHttpMock::sentRequests());
        }
    }

    public function testHmacTokenIsRejected(): void
    {
        $hs256 = JWT::encode(MicrosoftIdTokenFactory::defaultClaims(), str_repeat('shared-secret-guess', 4), 'HS256', MicrosoftIdTokenFactory::KEY_ID);

        $this->expectException(InvalidMicrosoftIdToken::class);

        $this->verifier()->verify($hs256);
    }

    public function testMissingOrMalformedTokenIsRejected(): void
    {
        foreach ([null, '', 'not-a-jwt', 42] as $idToken) {
            try {
                $this->verifier()->verify($idToken);
                self::fail('Must be rejected: ' . var_export($idToken, true));
            } catch (InvalidMicrosoftIdToken) {
                // expected
            }
        }

        self::assertSame([], SocialLoginHttpMock::sentRequests());
    }

    public function testNoClientIdConfiguredAcceptsNothing(): void
    {
        $this->expectException(InvalidMicrosoftIdToken::class);

        $this->verifier(clientId: '')->verify($this->token(['aud' => '']));
    }

    public function testKeysAreCachedBetweenSignIns(): void
    {
        SocialLoginHttpMock::queue(MicrosoftIdTokenFactory::jwksResponse());
        $verifier = $this->verifier();

        $verifier->verify($this->token([]));
        $verifier->verify($this->token([]));

        self::assertCount(1, SocialLoginHttpMock::sentRequests());
    }

    public function testUnknownKeyIdRefetchesOnceThenIsThrottled(): void
    {
        SocialLoginHttpMock::queue(MicrosoftIdTokenFactory::jwksResponse(), MicrosoftIdTokenFactory::jwksResponse());
        $verifier = $this->verifier();
        $verifier->verify($this->token([]));

        $this->clock->modify('+10 minutes');

        // Key rollover: an unknown kid triggers one refetch ...
        try {
            $verifier->verify($this->token([], keyId: 'rolled-over-kid'));
            self::fail('Unknown kid must be rejected');
        } catch (InvalidMicrosoftIdToken) {
        }

        self::assertCount(2, SocialLoginHttpMock::sentRequests());

        // ... and a flood of made-up kids within the throttle window fetches nothing more
        for ($i = 0; $i < 5; $i++) {
            try {
                $verifier->verify($this->token([], keyId: 'made-up-' . $i));
            } catch (InvalidMicrosoftIdToken) {
            }
        }

        self::assertCount(2, SocialLoginHttpMock::sentRequests());
    }

    public function testUnreachableJwksIsRejected(): void
    {
        SocialLoginHttpMock::queue(new \GuzzleHttp\Psr7\Response(503, [], 'down'));

        $this->expectException(InvalidMicrosoftIdToken::class);

        $this->verifier()->verify($this->token([]));
    }

    private function verifier(string $clientId = MicrosoftIdTokenFactory::CLIENT_ID): MicrosoftIdTokenVerifier
    {
        return new MicrosoftIdTokenVerifier(
            new CachedJwks(SocialLoginHttpMock::client(), $this->cache, $this->clock),
            $this->clock,
            $clientId,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function token(array $claims, string $keyId = MicrosoftIdTokenFactory::KEY_ID): string
    {
        // The mock clock starts at the real "now", the factory's defaults too
        return MicrosoftIdTokenFactory::idToken($claims, $keyId);
    }
}
