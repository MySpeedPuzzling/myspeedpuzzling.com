<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The "Open Gmail" shortcut on the check-your-email screens
 * (docs/features/auth-ux-redesign.md §4.4): offered only for the big webmail
 * providers, whose inbox URL we know - and whose app, on phones, those URLs
 * open when it is installed. Any other domain gets no button rather than a
 * wrong guess.
 */
final readonly class WebmailProvider
{
    private function __construct(
        public string $name,
        public string $inboxUrl,
    ) {
    }

    public static function fromEmail(string $email): null|self
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $domain = strtolower(trim(substr($email, $at + 1)));

        return match (true) {
            in_array($domain, ['gmail.com', 'googlemail.com'], true) => new self('Gmail', 'https://mail.google.com/'),
            $domain === 'msn.com',
            self::isAnyTld($domain, 'outlook'),
            self::isAnyTld($domain, 'hotmail'),
            self::isAnyTld($domain, 'live') => new self('Outlook', 'https://outlook.live.com/mail/'),
            $domain === 'ymail.com',
            self::isAnyTld($domain, 'yahoo') => new self('Yahoo Mail', 'https://mail.yahoo.com/'),
            in_array($domain, ['icloud.com', 'me.com', 'mac.com'], true) => new self('iCloud Mail', 'https://www.icloud.com/mail'),
            // The largest Czech webmail - a big share of MySpeedPuzzling's players
            in_array($domain, ['seznam.cz', 'email.cz', 'post.cz', 'spoluzaci.cz'], true) => new self('Seznam Email', 'https://email.seznam.cz/'),
            default => null,
        };
    }

    /**
     * "hotmail.co.uk", "live.fr", "yahoo.com.br" - one label before a public
     * suffix; "live.example.com" is somebody else's server.
     */
    private static function isAnyTld(string $domain, string $label): bool
    {
        return preg_match('/^' . preg_quote($label, '/') . '\.(?:[a-z]{2,3}\.)?[a-z]{2,3}$/', $domain) === 1;
    }
}
