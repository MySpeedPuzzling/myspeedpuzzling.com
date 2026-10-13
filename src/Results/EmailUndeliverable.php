<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Why e-mails to a player's address do not arrive (an SES permanent bounce), for the banner
 */
readonly final class EmailUndeliverable
{
    public function __construct(
        /** SES bounceSubType: NoEmail, General, Suppressed, OnAccountSuppressionList, … */
        public null|string $bounceSubType,
        /** The receiving server's own words, e.g. "smtp; 550 5.1.1 user unknown" */
        public null|string $diagnosticCode,
    ) {
    }

    /**
     * Translation key of the reason in plain words, null when SES gave none we can explain
     */
    public function reasonKey(): null|string
    {
        return match ($this->bounceSubType) {
            'NoEmail' => 'email_undeliverable.reason.no_email',
            'Suppressed', 'OnAccountSuppressionList' => 'email_undeliverable.reason.suppressed',
            'General' => 'email_undeliverable.reason.general',
            default => null,
        };
    }
}
