<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Email;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Email\EmailDomainMailCheck;
use SpeedPuzzling\Web\Tests\TestDouble\ScriptedDnsRecordLookup;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EmailDomainMailCheckTest extends TestCase
{
    private ScriptedDnsRecordLookup $dns;
    private EmailDomainMailCheck $check;

    protected function setUp(): void
    {
        $this->dns = new ScriptedDnsRecordLookup();
        $this->check = new EmailDomainMailCheck($this->dns, new ArrayAdapter());
    }

    public function testANullMxAcceptsNoMail(): void
    {
        // dns_get_record() returns the "." of a null MX as an empty target
        ScriptedDnsRecordLookup::script('nomail.test', DNS_MX, [['pri' => 0, 'target' => '']]);

        self::assertTrue($this->check->acceptsNoMail('someone@NoMail.test'));
    }

    public function testADomainWithAMailServerAcceptsMail(): void
    {
        ScriptedDnsRecordLookup::script('mail.test', DNS_MX, [['pri' => 10, 'target' => 'mx1.mail.test']]);

        self::assertFalse($this->check->acceptsNoMail('someone@mail.test'));
    }

    public function testWithoutMxTheAddressRecordReceivesTheMail(): void
    {
        ScriptedDnsRecordLookup::script('implicit.test', DNS_MX, []);
        ScriptedDnsRecordLookup::script('implicit.test', DNS_A, [['ip' => '192.0.2.1']]);

        self::assertFalse($this->check->acceptsNoMail('someone@implicit.test'));
    }

    public function testADomainWithoutAnyRecordAcceptsNoMail(): void
    {
        ScriptedDnsRecordLookup::script('gone.test', DNS_MX, []);
        ScriptedDnsRecordLookup::script('gone.test', DNS_A, []);
        ScriptedDnsRecordLookup::script('gone.test', DNS_AAAA, []);

        self::assertTrue($this->check->acceptsNoMail('someone@gone.test'));
    }

    public function testAFailedLookupNeverRefusesAndIsAskedAgain(): void
    {
        self::assertFalse($this->check->acceptsNoMail('someone@unreachable.test'));
        self::assertFalse($this->check->acceptsNoMail('someone@unreachable.test'));
        self::assertSame(2, $this->dns->lookups);
    }

    public function testAFailedAddressLookupNeverRefuses(): void
    {
        ScriptedDnsRecordLookup::script('half.test', DNS_MX, []);

        self::assertFalse($this->check->acceptsNoMail('someone@half.test'));
    }

    public function testAnAnswerIsCachedPerDomain(): void
    {
        ScriptedDnsRecordLookup::script('nomail.test', DNS_MX, [['pri' => 0, 'target' => '']]);

        $this->check->acceptsNoMail('a@nomail.test');
        $this->check->acceptsNoMail('b@nomail.test');

        self::assertSame(1, $this->dns->lookups);
    }

    public function testNotADomainToJudge(): void
    {
        self::assertFalse($this->check->acceptsNoMail('no-at-sign'));
        self::assertFalse($this->check->acceptsNoMail('someone@localhost'));
        self::assertFalse($this->check->acceptsNoMail('someone@[192.0.2.1]'));
        self::assertSame(0, $this->dns->lookups);
    }
}
