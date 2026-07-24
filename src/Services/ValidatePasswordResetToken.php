<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\InvalidPasswordResetToken;
use SpeedPuzzling\Web\Exceptions\PasswordResetTokenExpired;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Value\PasswordResetToken;

readonly final class ValidatePasswordResetToken
{
    public function __construct(
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidPasswordResetToken
     * @throws PasswordResetTokenExpired
     */
    public function validate(#[SensitiveParameter] string $token): UserAccount
    {
        $resetToken = PasswordResetToken::fromString($token);
        $request = $this->resetPasswordRequestRepository->findBySelector($resetToken->selector);

        if ($request === null) {
            throw new InvalidPasswordResetToken();
        }

        if (!hash_equals($request->hashedVerifier, $resetToken->hashedVerifier())) {
            throw new InvalidPasswordResetToken();
        }

        if ($request->isExpired($this->clock->now())) {
            throw new PasswordResetTokenExpired();
        }

        return $request->userAccount;
    }
}
