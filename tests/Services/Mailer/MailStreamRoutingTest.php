<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Mailer;

use SpeedPuzzling\Web\Tests\TestClock;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailSuppression;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Services\Mailer\MailStreamRouting;
use SpeedPuzzling\Web\Services\Mailer\SesMessageHeaders;
use SpeedPuzzling\Web\Tests\TestDouble\ScriptedTransports;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class MailStreamRoutingTest extends TestCase
{
    public function testEveryStreamGoesThroughTheOneTransportMarkedWithItsStream(): void
    {
        $transports = new ScriptedTransports();
        $routing = $this->routing($transports);

        $routing->send($this->email('player@example.com', 'notifications'));
        $routing->send($this->email('player@example.com'));

        self::assertSame(2, $transports->attempts);
        self::assertSame(['notifications', 'transactional'], $transports->streams);
        self::assertSame([null, null], $transports->transportHeaders, 'X-Transport is taken off: there is one transport');
    }

    public function testAFailedAttemptIsTriedOnceMore(): void
    {
        $transports = new ScriptedTransports([self::staleConnection()]);

        $this->routing($transports)->send($this->email('player@example.com'));

        self::assertSame(2, $transports->attempts);
        self::assertSame(['transactional'], $transports->streams);
    }

    public function testTwoFailuresGoBackToMessengerAndPauseTheTransportForAMinuteNoOtherProvider(): void
    {
        $clock = new MockClock('2026-10-19 10:00:00');
        $transports = new ScriptedTransports([self::staleConnection(), self::staleConnection()]);
        $routing = $this->routing($transports, $clock);

        try {
            $routing->send($this->email('player@example.com'));
            self::fail('Expected the failure - Messenger retries the message');
        } catch (TransportExceptionInterface $exception) {
            self::assertStringContainsString('451', $exception->getMessage());
        }

        // Paused: refused at once, the transport is not asked
        $clock->sleep(MailStreamRouting::PAUSE_SECONDS - 1);

        try {
            $routing->send($this->email('player@example.com'));
            self::fail('Expected the pause');
        } catch (TransportException $exception) {
            self::assertStringContainsString('not tried until', $exception->getMessage());
        }

        self::assertSame(2, $transports->attempts);

        // After the pause the transport is asked again
        $clock->sleep(2);
        $routing->send($this->email('player@example.com'));
        self::assertSame(3, $transports->attempts);
    }

    public function testARecipientRejectionIsFinalNoRetryNoPause(): void
    {
        $transports = new ScriptedTransports([new UnexpectedResponseException('550 Mailbox unavailable', 550)]);
        $routing = $this->routing($transports);

        try {
            $routing->send($this->email('gone@example.com'));
            self::fail('Expected the rejection');
        } catch (UnexpectedResponseException $exception) {
            self::assertSame(550, $exception->getCode());
        }

        $routing->send($this->email('player@example.com'));

        self::assertSame(2, $transports->attempts);
    }

    public function testAnApiRefusalIsNotRepeatedAndDoesNotPause(): void
    {
        $transports = new ScriptedTransports([$this->apiRefusal(400)]);
        $routing = $this->routing($transports);

        try {
            $routing->send($this->email('player@example.com'));
            self::fail('Expected the refusal');
        } catch (HttpTransportException) {
        }

        $routing->send($this->email('player@example.com'));

        self::assertSame(2, $transports->attempts);
    }

    public function testNotificationsToASuppressedAddressAreDroppedButTransactionalMailIsStillTried(): void
    {
        $transports = new ScriptedTransports();
        $suppressions = $this->createStub(EmailSuppressionRepository::class);
        $suppressions->method('findByEmail')->willReturnCallback(static fn (string $email): null|EmailSuppression => $email === 'gone@example.com'
            ? new EmailSuppression(Uuid::uuid7(), $email, TestClock::now(), 'NoEmail', null, null)
            : null);
        $routing = new MailStreamRouting($transports, $suppressions, new NullLogger(), new MockClock(TestClock::now()));

        self::assertNull($routing->send($this->email('gone@example.com', 'notifications')));
        $routing->send($this->email('gone@example.com'));

        self::assertSame(['transactional'], $transports->streams);
    }

    public function testAnAddressBlockedByHandGetsNothingAtAllNotEvenASignInLink(): void
    {
        $transports = new ScriptedTransports();
        $suppressions = $this->createStub(EmailSuppressionRepository::class);
        $suppressions->method('findByEmail')->willReturnCallback(static fn (string $email): null|EmailSuppression => $email === 'typo@googlemail.mail.com'
            ? EmailSuppression::blockedByHand(Uuid::uuid7(), $email, TestClock::now(), 'The domain does not exist.')
            : null);
        $routing = new MailStreamRouting($transports, $suppressions, new NullLogger(), new MockClock(TestClock::now()));

        self::assertNull($routing->send($this->email('typo@googlemail.mail.com', 'notifications')));
        self::assertNull($routing->send($this->email('typo@googlemail.mail.com')));
        $routing->send($this->email('player@example.com'));

        self::assertSame(['transactional'], $transports->streams, 'Only the other address got its mail');
    }

    public function testTheApiTransportIsRecognisedAsSesAndItsAccessKeyIdIsNeverStored(): void
    {
        self::assertTrue(SesMessageHeaders::isSes('ses+https://AKIAEXAMPLE@eu-north-1'));
        self::assertTrue(SesMessageHeaders::isSes('smtp://email-smtp.eu-north-1.amazonaws.com:587'));
        self::assertFalse(SesMessageHeaders::isSes('smtp://smtp.seznam.cz:587'));

        self::assertSame('ses+https://eu-north-1', SesMessageHeaders::transportLabel('ses+https://AKIAEXAMPLE@eu-north-1'));
        self::assertSame('smtp://smtp.seznam.cz:587', SesMessageHeaders::transportLabel('smtp://smtp.seznam.cz:587'));
    }

    private function routing(ScriptedTransports $transports, null|MockClock $clock = null): MailStreamRouting
    {
        $suppressions = $this->createStub(EmailSuppressionRepository::class);
        $suppressions->method('findByEmail')->willReturn(null);

        return new MailStreamRouting($transports, $suppressions, new NullLogger(), $clock ?? new MockClock(TestClock::now()));
    }

    private function email(string $to, null|string $transport = null): Email
    {
        $email = (new Email())->from('robot@mail.myspeedpuzzling.com')->to($to)->subject('Hi')->text('Hi');

        if ($transport !== null) {
            $email->getHeaders()->addTextHeader('X-Transport', $transport);
        }

        return $email;
    }

    private static function staleConnection(): UnexpectedResponseException
    {
        return new UnexpectedResponseException('Expected response code "250" but got code "451", with message "451 4.4.2 Timeout waiting for data from client.".', 451);
    }

    private function apiRefusal(int $status): HttpTransportException
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);

        return new HttpTransportException('Unable to send an email: Email address is not verified (code 400).', $response, $status);
    }
}
