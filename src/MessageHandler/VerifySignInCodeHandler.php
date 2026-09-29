<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\VerifySignInCode;
use SpeedPuzzling\Web\Repository\LoginLinkRequestRepository;
use SpeedPuzzling\Web\Services\SignInCodeHasher;
use SpeedPuzzling\Web\Value\SignInCodeCheck;
use SpeedPuzzling\Web\Value\SignInCodeOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Checks a typed sign-in code against its request (auth UX redesign phase 2).
 *
 * - The row is locked for the check, so parallel guesses cannot outrun the cap.
 * - A wrong code counts; the 5th wrong one kills the CODE only. The link in the
 *   same mail keeps working: guessing digits teaches nothing about the link's
 *   signature, and whoever holds the mail can still get in with one tap -
 *   killing it too would only punish the person who mistyped.
 * - The right code consumes the request: the link dies with it (no scanner
 *   grace window - codeUsedAt), exactly as a used link kills the code.
 *
 * Never throws for a rejected code: the attempt counter must be committed, and
 * a handler that throws is rolled back.
 */
#[AsMessageHandler]
final readonly class VerifySignInCodeHandler
{
    public function __construct(
        private LoginLinkRequestRepository $loginLinkRequestRepository,
        private SignInCodeHasher $signInCodeHasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(VerifySignInCode $message): SignInCodeCheck
    {
        $loginLinkRequest = $this->loginLinkRequestRepository->getForCodeCheck($message->requestId);

        if ($loginLinkRequest === null || $loginLinkRequest->codeHash === null) {
            return SignInCodeCheck::rejected(SignInCodeOutcome::Unknown);
        }

        $now = $this->clock->now();

        if ($loginLinkRequest->isConsumed()) {
            return SignInCodeCheck::rejected(SignInCodeOutcome::Used);
        }

        if ($loginLinkRequest->expiresAt <= $now) {
            return SignInCodeCheck::rejected(SignInCodeOutcome::Expired);
        }

        if ($loginLinkRequest->codeAttemptsLeft() === 0) {
            return SignInCodeCheck::rejected(SignInCodeOutcome::LockedOut);
        }

        if (!$this->signInCodeHasher->matches($loginLinkRequest->id, $message->code, $loginLinkRequest->codeHash)) {
            $loginLinkRequest->recordWrongCode();

            return SignInCodeCheck::wrong($loginLinkRequest->codeAttemptsLeft());
        }

        $loginLinkRequest->consumeWithCode($now);

        return SignInCodeCheck::accepted(
            $loginLinkRequest->userAccount->userId,
            $loginLinkRequest->returnPath,
        );
    }
}
