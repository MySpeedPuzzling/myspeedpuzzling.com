<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * When a full inbox pauses notifications (docs/features/mass-mailing/README.md, rules): SES gives up on every e-mail to
 * a full inbox after 14 h and calls it transient. One full inbox is nothing; the same inbox full on three e-mails within
 * 30 days is a mailbox nobody empties, and every further digest costs reputation. The pause ends by itself - on the first
 * e-mail delivered to the address (sign-in links and other transactional mail are still tried) or after 30 days.
 */
final class MailboxFullRule
{
    public const int BOUNCES = 3;
    public const int WITHIN_DAYS = 30;
    public const int EXPIRES_AFTER_DAYS = 30;

    /**
     * The SQL condition (on an email_suppression alias) for a suppression that still holds mail back
     */
    public static function sqlInEffect(string $alias): string
    {
        return sprintf(
            "(%1\$s.source <> '%2\$s' OR %1\$s.suppressed_at > NOW() - INTERVAL '%3\$d days')",
            $alias,
            EmailSuppressionSource::MailboxFull->value,
            self::EXPIRES_AFTER_DAYS,
        );
    }
}
