<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Mailer;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Value\MailStream;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\RawMessage;

/**
 * Sits in front of the mailer's transports (decorates `mailer.transports`), where X-Transport still says which
 * stream a message belongs to, and decides which transport sends it (docs/features/mass-mailing/README.md
 * "Reliability"):
 *
 * - marks the stream on the message for the SES headers (SesMessageHeaders::STREAM_HEADER);
 * - drops notifications to an address that hard-bounced (email_suppression) - transactional mail is still tried,
 *   a sign-in link must never be held back by us;
 * - with MAILER_SES_DSN set, every stream goes through Amazon SES first. A failed attempt is tried once more right
 *   away (a fresh connection - SES replaces the machines behind its endpoint), after a second failure SES is left
 *   alone for SES_PAUSE_SECONDS (an outage costs one wait, not one per e-mail), and only **transactional** mail then
 *   falls back to its stream's own transport (Seznam). Notifications never do: they are queued and tried again
 *   later (Messenger's retry, the digest's own deferral) - bulk mail must never land on the fallback's daily limit,
 *   which is what took the fallback down on 2026-10-19. Primary first for every message, unlike Symfony's
 *   failover(), which stays on the second transport after one hiccup and answers "No transports found" once both
 *   failed within a minute;
 * - MAILER_SES_DSN null://null = SES off: every stream on its own transport (the rollback).
 *
 * The pause is kept for the life of the worker on purpose (no ResetInterface): it holds no user data, and a reset
 * between messages would make every message wait for a dead SES again.
 */
final class MailStreamRouting implements TransportInterface
{
    public const string SES_TRANSPORT = 'ses';
    public const int SES_PAUSE_SECONDS = 60;

    private null|\DateTimeImmutable $sesPausedUntil = null;

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly EmailSuppressionRepository $emailSuppressionRepository,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly string $sesDsn,
    ) {
    }

    public function send(RawMessage $message, null|Envelope $envelope = null): null|SentMessage
    {
        if (!$message instanceof Message) {
            return $this->inner->send($message, $envelope);
        }

        $headers = $message->getHeaders();
        $stream = MailStream::fromTransportName($headers->get('X-Transport')?->getBodyAsString());

        $headers->remove(SesMessageHeaders::STREAM_HEADER);
        $headers->addTextHeader(SesMessageHeaders::STREAM_HEADER, $stream->value);

        if ($stream === MailStream::Notifications) {
            foreach (self::recipients($message, $envelope) as $recipient) {
                if ($this->emailSuppressionRepository->isSuppressed($recipient)) {
                    $this->logger->info('Notification e-mail not sent: the address is suppressed after a hard bounce', [
                        'recipient' => $recipient,
                    ]);

                    return null;
                }
            }
        }

        if (!$this->isSesConfigured()) {
            return $this->inner->send($message, $envelope);
        }

        try {
            return $this->sendThroughSes($message, $envelope);
        } catch (TransportExceptionInterface $sesFailure) {
            if ($stream !== MailStream::Transactional || self::isRecipientRejection($sesFailure)) {
                throw $sesFailure;
            }

            return $this->sendThroughFallback($message, $envelope, $stream, $sesFailure);
        }
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    private function sendThroughSes(Message $message, null|Envelope $envelope): null|SentMessage
    {
        $now = $this->clock->now();

        if ($this->sesPausedUntil !== null && $now < $this->sesPausedUntil) {
            throw new TransportException(sprintf(
                'Amazon SES is not tried until %s, it failed twice in a row',
                $this->sesPausedUntil->format(\DateTimeInterface::ATOM),
            ));
        }

        self::useTransport($message->getHeaders(), self::SES_TRANSPORT);

        try {
            $sent = $this->inner->send($message, $envelope);
        } catch (TransportExceptionInterface $firstFailure) {
            if (self::isRecipientRejection($firstFailure) || self::isRefusal($firstFailure)) {
                throw $firstFailure;
            }

            $this->logger->info('Amazon SES failed to send an e-mail, trying once more', [
                'exception' => $firstFailure,
            ]);

            try {
                $sent = $this->inner->send($message, $envelope);
            } catch (TransportExceptionInterface $secondFailure) {
                if (self::isRecipientRejection($secondFailure) || self::isRefusal($secondFailure)) {
                    throw $secondFailure;
                }

                $this->sesPausedUntil = $this->clock->now()->modify(sprintf('+%d seconds', self::SES_PAUSE_SECONDS));

                $this->logger->warning('Amazon SES failed twice in a row, not tried for a minute', [
                    'exception' => $secondFailure,
                    'first_failure' => $firstFailure->getMessage(),
                ]);

                throw $secondFailure;
            }
        }

        $this->sesPausedUntil = null;

        return $sent;
    }

    private function sendThroughFallback(Message $message, null|Envelope $envelope, MailStream $stream, TransportExceptionInterface $sesFailure): null|SentMessage
    {
        self::useTransport($message->getHeaders(), $stream->value);

        try {
            $sent = $this->inner->send($message, $envelope);
        } catch (TransportExceptionInterface $fallbackFailure) {
            throw new TransportException(
                sprintf('Amazon SES and the fallback transport both failed. Amazon SES: %s Fallback: %s', $sesFailure->getMessage(), $fallbackFailure->getMessage()),
                0,
                $fallbackFailure,
            );
        }

        $this->logger->info('Transactional e-mail sent through the fallback transport, Amazon SES failed', [
            'exception' => $sesFailure,
        ]);

        return $sent;
    }

    private function isSesConfigured(): bool
    {
        return $this->sesDsn !== '' && !str_starts_with($this->sesDsn, 'null://');
    }

    /**
     * The mailbox does not exist / is full / is refused (SMTP 550-553): no other attempt or transport changes that
     */
    private static function isRecipientRejection(TransportExceptionInterface $exception): bool
    {
        return $exception instanceof UnexpectedResponseException && in_array($exception->getCode(), [550, 551, 552, 553], true);
    }

    /**
     * The SES API refused this message (400 MessageRejected, an unverified sender, a paused account): the same
     * request again changes nothing - only 429 (throttled) is worth repeating, and AsyncAws already repeated it
     */
    private static function isRefusal(TransportExceptionInterface $exception): bool
    {
        return $exception instanceof HttpTransportException
            && $exception->getCode() >= 400
            && $exception->getCode() < 500
            && $exception->getCode() !== 429;
    }

    /**
     * Transports picks the transport by X-Transport (and puts it back when sending failed)
     */
    private static function useTransport(Headers $headers, string $transport): void
    {
        $headers->remove('X-Transport');
        $headers->addTextHeader('X-Transport', $transport);
    }

    /**
     * @return list<string>
     */
    private static function recipients(Message $message, null|Envelope $envelope): array
    {
        if ($envelope !== null) {
            $addresses = $envelope->getRecipients();
        } elseif ($message instanceof Email) {
            $addresses = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];
        } else {
            $addresses = [];
        }

        return array_values(array_unique(array_map(
            static fn (Address $address): string => mb_strtolower(trim($address->getAddress())),
            $addresses,
        )));
    }
}
