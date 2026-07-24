<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SensitiveParameter;
use SpeedPuzzling\Web\Exceptions\InvalidPasswordResetToken;

final readonly class PasswordResetToken
{
    private const int SELECTOR_HEX_LENGTH = 32;
    private const int VERIFIER_HEX_LENGTH = 32;

    private function __construct(
        public string $selector,
        #[SensitiveParameter]
        public string $verifier,
    ) {
    }

    public static function generate(): self
    {
        return new self(
            bin2hex(random_bytes(self::SELECTOR_HEX_LENGTH / 2)),
            bin2hex(random_bytes(self::VERIFIER_HEX_LENGTH / 2)),
        );
    }

    /**
     * @throws InvalidPasswordResetToken
     */
    public static function fromString(#[SensitiveParameter] string $token): self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            throw new InvalidPasswordResetToken();
        }

        return new self(
            substr($token, 0, self::SELECTOR_HEX_LENGTH),
            substr($token, self::SELECTOR_HEX_LENGTH),
        );
    }

    public function toString(): string
    {
        return $this->selector . $this->verifier;
    }

    public function hashedVerifier(): string
    {
        return hash('sha256', $this->verifier);
    }
}
