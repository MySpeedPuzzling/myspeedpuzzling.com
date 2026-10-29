<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\EmailSuppressionSource;

/**
 * Why e-mails to a player's address do not arrive (an SES permanent bounce, a domain that does not exist, or a block
 * by hand), for the banner
 */
readonly final class EmailUndeliverable
{
    public function __construct(
        /** SES bounceSubType: NoEmail, General, Suppressed, OnAccountSuppressionList, … */
        public null|string $bounceSubType,
        /** The receiving server's own words, e.g. "smtp; 550 5.1.1 user unknown" */
        public null|string $diagnosticCode,
        public EmailSuppressionSource $source = EmailSuppressionSource::HardBounce,
        /** Blocked by hand: why, written by the team for the player */
        public null|string $reason = null,
    ) {
    }

    /**
     * Nothing at all is sent to the address, sign-in links included
     */
    public function blocksAllMail(): bool
    {
        return $this->source === EmailSuppressionSource::Manual;
    }

    /**
     * Translation key of the reason in plain words, null when SES gave none we can explain
     */
    public function reasonKey(): null|string
    {
        if ($this->source === EmailSuppressionSource::DomainNotFound) {
            return 'email_undeliverable.reason.domain_not_found';
        }

        return match ($this->bounceSubType) {
            'NoEmail' => 'email_undeliverable.reason.no_email',
            'Suppressed', 'OnAccountSuppressionList' => 'email_undeliverable.reason.suppressed',
            'General' => 'email_undeliverable.reason.general',
            default => null,
        };
    }
}
