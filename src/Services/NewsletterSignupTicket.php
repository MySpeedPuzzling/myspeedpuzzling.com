<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SpeedPuzzling\Web\Value\NewsletterSignupTicketCheck;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The "Send the confirmation e-mail?" step of the newsletter signup carries a ticket: when the step was shown,
 * HMAC-signed. Sending refuses a ticket younger than MINIMUM_SECONDS (a person reads the step, a bot posts at once)
 * or older than LIFETIME_SECONDS. Stateless - the footer form sits on shared-cached pages, so neither step may need
 * a session. Not bound to the address: the step lets the visitor correct it, and every send needs its own
 * single-use Turnstile token anyway.
 */
readonly final class NewsletterSignupTicket
{
    public const int MINIMUM_SECONDS = 3;
    public const int LIFETIME_SECONDS = 3600;

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        private string $secret,
        private ClockInterface $clock,
    ) {
    }

    public function issue(): string
    {
        $issuedAt = $this->clock->now()->getTimestamp();

        return $issuedAt . '.' . $this->signature($issuedAt);
    }

    public function check(string $ticket): NewsletterSignupTicketCheck
    {
        if (preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/', $ticket, $matches) !== 1) {
            return NewsletterSignupTicketCheck::Invalid;
        }

        $issuedAt = (int) $matches[1];

        if (!hash_equals($this->signature($issuedAt), $matches[2])) {
            return NewsletterSignupTicketCheck::Invalid;
        }

        $age = $this->clock->now()->getTimestamp() - $issuedAt;

        if ($age < self::MINIMUM_SECONDS) {
            return NewsletterSignupTicketCheck::TooFast;
        }

        if ($age > self::LIFETIME_SECONDS) {
            return NewsletterSignupTicketCheck::Expired;
        }

        return NewsletterSignupTicketCheck::Valid;
    }

    private function signature(int $issuedAt): string
    {
        return hash_hmac('sha256', 'newsletter-signup|' . $issuedAt, $this->secret);
    }
}
