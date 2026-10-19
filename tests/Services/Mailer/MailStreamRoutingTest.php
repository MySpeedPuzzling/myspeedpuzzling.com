<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Mailer;

use PHPUnit\Framework\TestCase;
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
    private const string SES_DSN = 'ses+https://key:secret@default?region=eu-north-1';
    private const string SEZNAM_DSN = 'smtp://robot:secret@smtp.seznam.cz:587';

    public function testWithSesOffEverythingStaysOnItsOwnTransport(): void
    {
        $transports = new ScriptedTransports();
        $routing = $this->routing($transports, sesDsn: 'null://null');

        $routing->send($this->email('player@example.com', 'notifications'));
        $routing->send($this->email('player@example.com'));

        self::assertSame(['notifications', null], $transports->attempts);
        self::assertSame(['notifications', 'transactional'], $transports->streams);
    }

    public function testWithSesOnEveryStreamGoesThroughSes(): void
    {
        $transports = new ScriptedTransports();
        $routing = $this->routing($transports);

        $routing->send($this->email('player@example.com', 'notifications'));
        $routing->send($this->email('player@example.com'));

        self::assertSame(['ses', 'ses'], $transports->attempts);
        self::assertSame(['notifications', 'transactional'], $transports->streams, 'The stream survives the reroute');
    }

    public function testAFailedSesAttemptIsTriedOnceMoreBeforeAnythingElse(): void
    {
        $transports = new ScriptedTransports(ses: [self::staleConnection()]);
        $routing = $this->routing($transports);

        $routing->send($this->email('player@example.com', 'notifications'));
        $routing->send($this->email('player@example.com'));

        self::assertSame(['ses', 'ses', 'ses'], $transports->attempts);
    }

    public function testTransactionalMailFallsBackOnlyAfterSesFailedTwiceAndSesIsTriedFirstAgainAfterThePause(): void
    {
        $clock = new MockClock('2026-10-19 10:00:00');
        $transports = new ScriptedTransports(ses: [self::staleConnection(), self::staleConnection()]);
        $routing = $this->routing($transports, clock: $clock);

        $routing->send($this->email('player@example.com'));
        self::assertSame(['ses', 'ses', 'transactional'], $transports->attempts);

        // Paused: straight to the fallback, SES is not asked
        $clock->sleep(MailStreamRouting::SES_PAUSE_SECONDS - 1);
        $routing->send($this->email('player@example.com'));
        self::assertSame(['ses', 'ses', 'transactional', 'transactional'], $transports->attempts);

        // Not sticky: once the pause is over SES is first again
        $clock->sleep(2);
        $routing->send($this->email('player@example.com'));
        self::assertSame(['ses', 'ses', 'transactional', 'transactional', 'ses'], $transports->attempts);
    }

    public function testWithoutARealTransactionalTransportSesIsTheOnlyOneAndAFailureIsLeftToMessengersRetry(): void
    {
        $transports = new ScriptedTransports(ses: [self::staleConnection(), self::staleConnection()]);
        $routing = $this->routing($transports, transactionalDsn: 'null://null');

        try {
            $routing->send($this->email('player@example.com'));
            self::fail('Expected the SES failure - never a "sent" into null://');
        } catch (TransportExceptionInterface $exception) {
            self::assertStringContainsString('451', $exception->getMessage());
        }

        self::assertSame(['ses', 'ses'], $transports->attempts);
    }

    public function testNotificationsNeverFallBackTheyFailToBeTriedLater(): void
    {
        $transports = new ScriptedTransports(ses: [self::staleConnection(), self::staleConnection()]);
        $routing = $this->routing($transports);

        try {
            $routing->send($this->email('player@example.com', 'notifications'));
            self::fail('Expected the SES failure');
        } catch (TransportExceptionInterface $exception) {
            self::assertStringContainsString('451', $exception->getMessage());
        }

        // Paused: refused at once, still never the fallback
        $this->expectException(TransportException::class);

        try {
            $routing->send($this->email('player@example.com', 'notifications'));
        } finally {
            self::assertSame(['ses', 'ses'], $transports->attempts);
        }
    }

    public function testARecipientRejectionIsFinalNoRetryNoFallbackNoPause(): void
    {
        $transports = new ScriptedTransports(ses: [new UnexpectedResponseException('550 Mailbox unavailable', 550)]);
        $routing = $this->routing($transports);

        try {
            $routing->send($this->email('gone@example.com'));
            self::fail('Expected the rejection');
        } catch (UnexpectedResponseException $exception) {
            self::assertSame(550, $exception->getCode());
        }

        $routing->send($this->email('player@example.com'));

        self::assertSame(['ses', 'ses'], $transports->attempts);
    }

    public function testAnApiRefusalIsNotRepeatedButTransactionalMailStillFallsBackWithoutPausingSes(): void
    {
        $transports = new ScriptedTransports(ses: [$this->apiRefusal(400)]);
        $routing = $this->routing($transports);

        $routing->send($this->email('player@example.com'));
        $routing->send($this->email('player@example.com'));

        self::assertSame(['ses', 'transactional', 'ses'], $transports->attempts);
    }

    public function testWhenSesAndTheFallbackBothFailBothCausesAreReported(): void
    {
        $transports = new ScriptedTransports(
            ses: [self::staleConnection(), self::staleConnection()],
            transactional: [new TransportException('525 Authentication refused')],
        );

        try {
            $this->routing($transports)->send($this->email('player@example.com'));
            self::fail('Expected both failures');
        } catch (TransportException $exception) {
            self::assertStringContainsString('451 4.4.2', $exception->getMessage());
            self::assertStringContainsString('525 Authentication refused', $exception->getMessage());
            self::assertStringNotContainsString('No transports found', $exception->getMessage());
        }
    }

    public function testNotificationsToASuppressedAddressAreDroppedButTransactionalMailIsStillTried(): void
    {
        $transports = new ScriptedTransports();
        $suppressions = $this->createStub(EmailSuppressionRepository::class);
        $suppressions->method('isSuppressed')->willReturnCallback(static fn (string $email): bool => $email === 'gone@example.com');
        $routing = new MailStreamRouting($transports, $suppressions, new NullLogger(), new MockClock(), self::SES_DSN, self::SEZNAM_DSN);

        self::assertNull($routing->send($this->email('gone@example.com', 'notifications')));
        $routing->send($this->email('gone@example.com'));

        self::assertSame(['transactional'], $transports->streams);
    }

    public function testTheApiTransportIsRecognisedAsSesAndItsAccessKeyIdIsNeverStored(): void
    {
        self::assertTrue(SesMessageHeaders::isSes('ses+https://AKIAEXAMPLE@eu-north-1'));
        self::assertTrue(SesMessageHeaders::isSes('smtp://email-smtp.eu-north-1.amazonaws.com:587'));
        self::assertFalse(SesMessageHeaders::isSes('smtp://smtp.seznam.cz:587'));

        self::assertSame('ses+https://eu-north-1', SesMessageHeaders::transportLabel('ses+https://AKIAEXAMPLE@eu-north-1'));
        self::assertSame('smtp://smtp.seznam.cz:587', SesMessageHeaders::transportLabel('smtp://smtp.seznam.cz:587'));
    }

    private function routing(ScriptedTransports $transports, string $sesDsn = self::SES_DSN, null|MockClock $clock = null, string $transactionalDsn = self::SEZNAM_DSN): MailStreamRouting
    {
        $suppressions = $this->createStub(EmailSuppressionRepository::class);
        $suppressions->method('isSuppressed')->willReturn(false);

        return new MailStreamRouting($transports, $suppressions, new NullLogger(), $clock ?? new MockClock(), $sesDsn, $transactionalDsn);
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
