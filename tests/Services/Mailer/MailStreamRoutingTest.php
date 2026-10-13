<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Mailer;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Services\Mailer\MailStreamRouting;
use SpeedPuzzling\Web\Services\Mailer\SesMessageHeaders;
use SpeedPuzzling\Web\Tests\TestDouble\TransportSpy;
use Symfony\Component\Mime\Email;

final class MailStreamRoutingTest extends TestCase
{
    public function testWithTheDefaultsEverythingStaysOnItsOwnTransport(): void
    {
        $spy = new TransportSpy();
        $routing = $this->routing($spy, testRecipients: '', sesDsn: 'null://null');

        $routing->send($this->email('player@example.com', 'notifications'));
        $routing->send($this->email('player@example.com'));

        self::assertSame(['notifications', null], $this->transports($spy));
        self::assertSame(['notifications', 'transactional'], $this->streams($spy));
    }

    public function testATestRecipientGoesThroughSesOnlyWhenEveryRecipientIsOnTheListAndSesIsConfigured(): void
    {
        $spy = new TransportSpy();
        $routing = $this->routing($spy, testRecipients: 'Jan@Example.com, second@example.com', sesDsn: 'smtp://user:pass@email-smtp.eu-central-1.amazonaws.com:587');

        $routing->send($this->email('jan@example.com', 'notifications'));
        $routing->send($this->email('player@example.com'));
        $routing->send($this->email('jan@example.com')->cc('player@example.com'));

        self::assertSame(['ses', null, null], $this->transports($spy));
        self::assertSame(['notifications', 'transactional', 'transactional'], $this->streams($spy), 'The stream survives the reroute');

        // Not configured: never route test recipients into a null transport
        $spy = new TransportSpy();
        $this->routing($spy, testRecipients: 'jan@example.com', sesDsn: 'null://null')->send($this->email('jan@example.com'));
        self::assertSame([null], $this->transports($spy));
    }

    public function testNotificationsToASuppressedAddressAreDroppedButTransactionalMailIsStillTried(): void
    {
        $spy = new TransportSpy();
        $suppressions = $this->createStub(EmailSuppressionRepository::class);
        $suppressions->method('isSuppressed')->willReturnCallback(static fn (string $email): bool => $email === 'gone@example.com');
        $routing = new MailStreamRouting($spy, $suppressions, new NullLogger(), '', 'null://null');

        self::assertNull($routing->send($this->email('gone@example.com', 'notifications')));
        $routing->send($this->email('gone@example.com'));

        self::assertSame(['transactional'], $this->streams($spy));
    }

    private function routing(TransportSpy $spy, string $testRecipients, string $sesDsn): MailStreamRouting
    {
        $suppressions = $this->createStub(EmailSuppressionRepository::class);
        $suppressions->method('isSuppressed')->willReturn(false);

        return new MailStreamRouting($spy, $suppressions, new NullLogger(), $testRecipients, $sesDsn);
    }

    private function email(string $to, null|string $transport = null): Email
    {
        $email = (new Email())->from('robot@mail.myspeedpuzzling.com')->to($to)->subject('Hi')->text('Hi');

        if ($transport !== null) {
            $email->getHeaders()->addTextHeader('X-Transport', $transport);
        }

        return $email;
    }

    /**
     * @return list<null|string>
     */
    private function transports(TransportSpy $spy): array
    {
        return array_map(
            static fn ($message): null|string => $message instanceof Email ? $message->getHeaders()->get('X-Transport')?->getBodyAsString() : null,
            $spy->sent,
        );
    }

    /**
     * @return list<null|string>
     */
    private function streams(TransportSpy $spy): array
    {
        return array_map(
            static fn ($message): null|string => $message instanceof Email ? $message->getHeaders()->get(SesMessageHeaders::STREAM_HEADER)?->getBodyAsString() : null,
            $spy->sent,
        );
    }
}
