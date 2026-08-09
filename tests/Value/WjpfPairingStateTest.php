<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\WjpfPairingState;

final class WjpfPairingStateTest extends TestCase
{
    public function testOpaqueTokenSurvives(): void
    {
        $state = WjpfPairingState::tryFrom('aB3-_.~xyz');

        self::assertNotNull($state);
        self::assertSame('aB3-_.~xyz', $state->value);
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame('abc', WjpfPairingState::tryFrom(' abc ')?->value);
    }

    /**
     * The value goes back into a URL we build, so anything that could add a parameter, start a
     * fragment or break a header is dropped rather than escaped.
     */
    #[DataProvider('hostileValues')]
    public function testHostileValuesAreRejected(string $value): void
    {
        self::assertNull(WjpfPairingState::tryFrom($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'query separator' => ['abc&code=stolen'];
        yield 'parameter break' => ['abc=def'];
        yield 'fragment' => ['abc#frag'];
        yield 'CR' => ["abc\rdef"];
        yield 'LF' => ["abc\ndef"];
        yield 'space' => ['abc def'];
        yield 'slash' => ['abc/def'];
        yield 'percent encoding' => ['abc%26def'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'empty' => [''];
    }

    public function testNullIsRejected(): void
    {
        self::assertNull(WjpfPairingState::tryFrom(null));
    }

    public function testMaximumLengthIsAccepted(): void
    {
        self::assertNotNull(WjpfPairingState::tryFrom(str_repeat('a', 128)));
    }
}
