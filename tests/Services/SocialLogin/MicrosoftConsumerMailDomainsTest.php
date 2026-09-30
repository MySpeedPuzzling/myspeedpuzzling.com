<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SocialLogin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\SocialLogin\MicrosoftConsumerMailDomains;

final class MicrosoftConsumerMailDomainsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'outlook.com' => ['someone@outlook.com', true];
        yield 'hotmail.com' => ['someone@hotmail.com', true];
        yield 'country variant' => ['someone@hotmail.co.uk', true];
        yield 'uppercase domain' => ['Someone@OUTLOOK.DE', true];
        yield 'live.nl' => ['someone@live.nl', true];
        yield 'msn.com' => ['someone@msn.com', true];
        yield 'gmail' => ['someone@gmail.com', false];
        yield 'look-alike tld' => ['someone@outlook.xyz', false];
        yield 'subdomain' => ['someone@mail.outlook.com', false];
        yield 'suffix trick' => ['someone@evil-outlook.com', false];
        yield 'domain in local part' => ['outlook.com@evil.example', false];
        yield 'Exchange Online tenant, not Outlook.com' => ['someone@outlook.cz', false];
        yield 'no at sign' => ['outlook.com', false];
    }

    #[DataProvider('addresses')]
    public function testOnlyExactMicrosoftConsumerDomainsCount(string $email, bool $expected): void
    {
        self::assertSame($expected, MicrosoftConsumerMailDomains::contains($email));
    }

    public function testListIsLowercaseAndUnique(): void
    {
        foreach (MicrosoftConsumerMailDomains::DOMAINS as $domain) {
            self::assertSame(strtolower($domain), $domain);
        }

        self::assertSame(MicrosoftConsumerMailDomains::DOMAINS, array_values(array_unique(MicrosoftConsumerMailDomains::DOMAINS)));
    }
}
