<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\WebmailProvider;

final class WebmailProviderTest extends TestCase
{
    /**
     * @return iterable<array{string, string, string}>
     */
    public static function knownProviders(): iterable
    {
        yield ['jane@gmail.com', 'Gmail', 'https://mail.google.com/'];
        yield ['Jane@GoogleMail.com', 'Gmail', 'https://mail.google.com/'];
        yield ['jane@outlook.com', 'Outlook', 'https://outlook.live.com/mail/'];
        yield ['jane@hotmail.co.uk', 'Outlook', 'https://outlook.live.com/mail/'];
        yield ['jane@live.fr', 'Outlook', 'https://outlook.live.com/mail/'];
        yield ['jane@yahoo.com.br', 'Yahoo Mail', 'https://mail.yahoo.com/'];
        yield ['jane@icloud.com', 'iCloud Mail', 'https://www.icloud.com/mail'];
        yield ['jane@me.com', 'iCloud Mail', 'https://www.icloud.com/mail'];
        yield ['jana@seznam.cz', 'Seznam Email', 'https://email.seznam.cz/'];
        yield ['jana@email.cz', 'Seznam Email', 'https://email.seznam.cz/'];
    }

    #[DataProvider('knownProviders')]
    public function testKnownWebmail(string $email, string $name, string $inboxUrl): void
    {
        $provider = WebmailProvider::fromEmail($email);

        self::assertNotNull($provider);
        self::assertSame($name, $provider->name);
        self::assertSame($inboxUrl, $provider->inboxUrl);
    }

    /**
     * No button rather than a wrong guess
     */
    public function testUnknownDomainsGetNoButton(): void
    {
        self::assertNull(WebmailProvider::fromEmail('jane@example.com'));
        self::assertNull(WebmailProvider::fromEmail('jane@mail.live.example.org'));
        self::assertNull(WebmailProvider::fromEmail('jane@gmail.com.evil.example'));
        self::assertNull(WebmailProvider::fromEmail('not-an-address'));
    }
}
