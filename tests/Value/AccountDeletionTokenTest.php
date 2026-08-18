<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidAccountDeletionToken;
use SpeedPuzzling\Web\Value\AccountDeletionToken;

final class AccountDeletionTokenTest extends TestCase
{
    public function testGeneratesSixtyFourHexCharsSplitInSelectorAndVerifier(): void
    {
        $token = AccountDeletionToken::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token->toString());
        self::assertSame($token->selector . $token->verifier, $token->toString());
        self::assertSame(32, strlen($token->selector));
        self::assertSame(32, strlen($token->verifier));
    }

    public function testRoundTripsThroughItsStringForm(): void
    {
        $token = AccountDeletionToken::generate();
        $parsed = AccountDeletionToken::fromString($token->toString());

        self::assertSame($token->selector, $parsed->selector);
        self::assertSame($token->verifier, $parsed->verifier);
        self::assertSame($token->hashedVerifier(), $parsed->hashedVerifier());
    }

    public function testTheStoredHashNeverEqualsTheVerifierItself(): void
    {
        $token = AccountDeletionToken::generate();

        self::assertNotSame($token->verifier, $token->hashedVerifier());
        self::assertSame(hash('sha256', $token->verifier), $token->hashedVerifier());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase hex' => [str_repeat('A', 64)];
        yield 'not hex' => [str_repeat('z', 64)];
    }

    #[DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(string $malformed): void
    {
        $this->expectException(InvalidAccountDeletionToken::class);

        AccountDeletionToken::fromString($malformed);
    }
}
