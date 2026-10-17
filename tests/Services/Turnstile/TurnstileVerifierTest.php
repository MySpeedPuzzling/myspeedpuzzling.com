<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Turnstile;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Services\Turnstile\TurnstileVerifier;
use SpeedPuzzling\Web\Tests\TestDouble\TurnstileHttpMock;
use SpeedPuzzling\Web\Value\TurnstileVerdict;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TurnstileVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        TurnstileHttpMock::reset();
    }

    public function testOffWithoutBothKeys(): void
    {
        self::assertFalse($this->verifier(siteKey: '')->isOn());
        self::assertFalse($this->verifier(secretKey: ' ')->isOn());
        self::assertTrue($this->verifier()->isOn());
    }

    public function testPassesOnlyWithTheExpectedActionAndHostname(): void
    {
        $verifier = $this->verifier();

        self::assertSame(TurnstileVerdict::Passed, $verifier->verify(TurnstileHttpMock::PASSING_TOKEN, 'newsletter', 'localhost', '203.0.113.7'));
        self::assertSame(TurnstileVerdict::Failed, $verifier->verify(TurnstileHttpMock::PASSING_TOKEN, 'newsletter', 'myspeedpuzzling.com', null));
        self::assertSame(TurnstileVerdict::Failed, $verifier->verify(TurnstileHttpMock::WRONG_ACTION_TOKEN, 'newsletter', 'localhost', null));
        self::assertSame(TurnstileVerdict::Failed, $verifier->verify('anything-else', 'newsletter', 'localhost', null));

        parse_str(TurnstileHttpMock::requestBodies()[0], $sent);
        self::assertSame(['secret' => 'secret-key', 'response' => TurnstileHttpMock::PASSING_TOKEN, 'remoteip' => '203.0.113.7'], $sent);
    }

    public function testNoAnswerIsUnavailable(): void
    {
        self::assertSame(TurnstileVerdict::Unavailable, $this->verifier()->verify(TurnstileHttpMock::DOWN_TOKEN, 'newsletter', 'localhost', null));
    }

    public function testMissingOrOversizedTokenFailsWithoutAsking(): void
    {
        $verifier = $this->verifier();

        self::assertSame(TurnstileVerdict::Failed, $verifier->verify(null, 'newsletter', 'localhost', null));
        self::assertSame(TurnstileVerdict::Failed, $verifier->verify('', 'newsletter', 'localhost', null));
        self::assertSame(TurnstileVerdict::Failed, $verifier->verify(str_repeat('x', 2049), 'newsletter', 'localhost', null));
        self::assertSame([], TurnstileHttpMock::requestBodies());
    }

    public function testCloudflareTestKeysPassWithoutActionAndHostname(): void
    {
        // What siteverify answers for the always-passing test secret (measured 2026-10-17)
        $client = new MockHttpClient(new MockResponse(
            '{"success":true,"hostname":"example.com","error-codes":[],"metadata":{"result_with_testing_key":true}}',
        ));

        self::assertSame(TurnstileVerdict::Passed, $this->verifier($client)->verify('XXXX.DUMMY.TOKEN.XXXX', 'newsletter', 'localhost', null));
    }

    private function verifier(null|HttpClientInterface $client = null, string $siteKey = 'site-key', string $secretKey = 'secret-key'): TurnstileVerifier
    {
        return new TurnstileVerifier($client ?? TurnstileHttpMock::client(), new NullLogger(), $siteKey, $secretKey);
    }
}
