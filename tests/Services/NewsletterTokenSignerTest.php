<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\NewsletterConfirmTokenExpired;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Component\Clock\MockClock;

final class NewsletterTokenSignerTest extends TestCase
{
    private MockClock $clock;

    private NewsletterTokenSigner $signer;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-26 12:00:00');
        $this->signer = new NewsletterTokenSigner('test-secret', $this->clock);
    }

    public function testUnsubscribeTokenRoundtrip(): void
    {
        $token = $this->signer->generateUnsubscribeToken(NewsletterAudience::Player, 'player-id', 'Someone@Example.com ');

        $claim = $this->signer->parseUnsubscribeToken($token);

        self::assertSame(NewsletterAudience::Player, $claim->audience);
        self::assertSame('player-id', $claim->id);
        self::assertSame('someone@example.com', $claim->email);
    }

    public function testUnsubscribeTokenIsDeterministic(): void
    {
        $first = $this->signer->generateUnsubscribeToken(NewsletterAudience::Guest, 'id', 'a@b.com');
        $second = $this->signer->generateUnsubscribeToken(NewsletterAudience::Guest, 'id', 'a@b.com');

        self::assertSame($first, $second);
    }

    public function testConfirmTokenRoundtrip(): void
    {
        $token = $this->signer->generateConfirmToken(NewsletterAudience::Guest, 'guest-id', 'guest@example.com');

        $claim = $this->signer->parseConfirmToken($token);

        self::assertSame(NewsletterAudience::Guest, $claim->audience);
        self::assertSame('guest-id', $claim->id);
        self::assertSame('guest@example.com', $claim->email);
    }

    public function testConfirmTokenExpires(): void
    {
        $token = $this->signer->generateConfirmToken(NewsletterAudience::Guest, 'guest-id', 'guest@example.com');

        $this->clock->modify('+49 hours');

        $this->expectException(NewsletterConfirmTokenExpired::class);
        $this->signer->parseConfirmToken($token);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token = $this->signer->generateUnsubscribeToken(NewsletterAudience::Player, 'player-id', 'someone@example.com');
        [$payload, $signature] = explode('.', $token);

        $forgedPayload = rtrim(strtr(base64_encode(str_replace('player-id', 'other-id', (string) base64_decode(strtr($payload, '-_', '+/'), true))), '+/', '-_'), '=');

        $this->expectException(InvalidNewsletterToken::class);
        $this->signer->parseUnsubscribeToken($forgedPayload . '.' . $signature);
    }

    public function testConfirmTokenCannotBeUsedAsUnsubscribeToken(): void
    {
        $token = $this->signer->generateConfirmToken(NewsletterAudience::Guest, 'guest-id', 'guest@example.com');

        $this->expectException(InvalidNewsletterToken::class);
        $this->signer->parseUnsubscribeToken($token);
    }

    public function testGarbageIsRejected(): void
    {
        $this->expectException(InvalidNewsletterToken::class);
        $this->signer->parseUnsubscribeToken('not-a-token');
    }

    public function testPreferencesTokenRoundtrip(): void
    {
        $token = $this->signer->generatePreferencesToken(NewsletterAudience::Player, 'player-id', 'Someone@Example.com ');

        $claim = $this->signer->parsePreferencesToken($token);

        self::assertSame(NewsletterAudience::Player, $claim->audience);
        self::assertSame('player-id', $claim->id);
        self::assertSame('someone@example.com', $claim->email);
    }

    public function testPreferencesTokenIsDeterministic(): void
    {
        $first = $this->signer->generatePreferencesToken(NewsletterAudience::Player, 'id', 'a@b.com');
        $second = $this->signer->generatePreferencesToken(NewsletterAudience::Player, 'id', 'a@b.com');

        self::assertSame($first, $second);
    }

    public function testUnsubscribeTokenCannotBeUsedAsPreferencesToken(): void
    {
        $token = $this->signer->generateUnsubscribeToken(NewsletterAudience::Player, 'player-id', 'someone@example.com');

        $this->expectException(InvalidNewsletterToken::class);
        $this->signer->parsePreferencesToken($token);
    }

    public function testPreferencesTokenCannotBeUsedAsUnsubscribeToken(): void
    {
        $token = $this->signer->generatePreferencesToken(NewsletterAudience::Player, 'player-id', 'someone@example.com');

        $this->expectException(InvalidNewsletterToken::class);
        $this->signer->parseUnsubscribeToken($token);
    }
}
