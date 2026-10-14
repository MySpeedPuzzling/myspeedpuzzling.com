<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveKit;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\LiveKitWebhookRejected;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitWebhookVerifier;
use Symfony\Component\Clock\MockClock;

/**
 * LiveKit's webhook signature (contract phase-1b.md §5): a JWT in `Authorization`, HS256 with the API secret, issued by
 * the API key, whose `sha256` claim is the base64 of the body's SHA-256 - LiveKit's own receivers check exactly this.
 */
final class LiveKitWebhookVerifierTest extends TestCase
{
    private const string KEY = 'test-key';
    private const string SECRET = 'a-test-secret-that-is-long-enough-for-hs256';
    private const int NOW = 1792000000;
    private const string BODY = '{"event":"participant_joined","room":{"name":"studio-018d0050-0000-0000-0000-000000000101"},"participant":{"identity":"p:018d0050-0000-0000-0000-000000000201"},"id":"EV_1","createdAt":"1792000000"}';

    public function testAWebhookOfOurServerIsRead(): void
    {
        $event = $this->verifier()->verify(self::BODY, self::signature(self::BODY));

        self::assertSame('participant_joined', $event['event']);
        self::assertSame(['name' => 'studio-018d0050-0000-0000-0000-000000000101'], $event['room']);

        // With "Bearer " as well, within the leeway of a minute
        self::assertSame('EV_1', $this->verifier(self::NOW + 359)->verify(self::BODY, 'Bearer ' . self::signature(self::BODY))['id']);
    }

    /**
     * @return iterable<string, array{0: null|string, 1?: string}>
     */
    public static function rejectedSignatures(): iterable
    {
        yield 'no header' => [null];
        yield 'an empty header' => [''];
        yield 'not a JWT' => ['Bearer something'];
        yield 'another secret' => [self::signature(self::BODY, secret: 'another-secret-that-is-long-enough-for-hs256')];
        yield 'another key' => [self::signature(self::BODY, key: 'another-key')];
        yield 'the hash of another body' => [self::signature('{"event":"room_finished"}')];
        yield 'no hash' => [self::signature(self::BODY, hash: null)];
        yield 'a hash that is no base64' => [self::signature(self::BODY, hash: '***')];
        yield 'expired' => [self::signature(self::BODY, expiresAt: self::NOW - 61)];
        yield 'never expiring' => [self::signature(self::BODY, expiresAt: null)];
        yield 'another algorithm' => [JWT::encode(['iss' => self::KEY, 'exp' => self::NOW + 300, 'sha256' => base64_encode(hash('sha256', self::BODY, true))], self::SECRET . self::SECRET, 'HS512')];
    }

    #[DataProvider('rejectedSignatures')]
    public function testAnythingElseIsRejected(null|string $authorization): void
    {
        $this->expectException(LiveKitWebhookRejected::class);
        $this->verifier()->verify(self::BODY, $authorization);
    }

    public function testABodyThatIsNoJsonObjectIsRejected(): void
    {
        $this->expectException(LiveKitWebhookRejected::class);
        $this->verifier()->verify('[1,2]', self::signature('[1,2]'));
    }

    public function testNothingIsVerifiedWithoutAServer(): void
    {
        $verifier = new LiveKitWebhookVerifier(new LiveKitSettings('', '', '', ''), new MockClock(new DateTimeImmutable('@' . self::NOW)));

        $this->expectException(LiveKitWebhookRejected::class);
        $verifier->verify(self::BODY, self::signature(self::BODY));
    }

    public function testTheJwtLibrarysClockIsLeftAsItWas(): void
    {
        $this->verifier()->verify(self::BODY, self::signature(self::BODY));

        self::assertNull(JWT::$timestamp);
        self::assertSame(0, JWT::$leeway);
    }

    private function verifier(int $now = self::NOW): LiveKitWebhookVerifier
    {
        return new LiveKitWebhookVerifier(
            new LiveKitSettings('http://livekit:7880', 'wss://live.example.test', self::KEY, self::SECRET),
            new MockClock(new DateTimeImmutable('@' . $now)),
        );
    }

    /**
     * What LiveKit puts into `Authorization` - valid five minutes
     */
    private static function signature(
        string $body,
        string $key = self::KEY,
        string $secret = self::SECRET,
        null|string $hash = 'body',
        null|int $expiresAt = self::NOW + 300,
    ): string {
        $claims = ['iss' => $key, 'nbf' => self::NOW];

        if ($expiresAt !== null) {
            $claims['exp'] = $expiresAt;
        }

        if ($hash !== null) {
            $claims['sha256'] = $hash === 'body' ? base64_encode(hash('sha256', $body, true)) : $hash;
        }

        return JWT::encode($claims, $secret, 'HS256');
    }
}
