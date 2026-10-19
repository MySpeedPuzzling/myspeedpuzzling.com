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
use Symfony\Component\Mime\RawMessage;

/**
 * Sits in front of the mailer's transport (decorates `mailer.transports`), where X-Transport still says which stream
 * a message belongs to (docs/features/mass-mailing/README.md "Reliability"):
 *
 * - marks the stream on the message for the SES headers (SesMessageHeaders::STREAM_HEADER) and takes X-Transport off -
 *   there is one transport, MAILER_DSN (Amazon SES on production, SES only since 2026-10-19);
 * - drops notifications to an address that hard-bounced (email_suppression) - transactional mail is still tried,
 *   a sign-in link must never be held back by us;
 * - a failed attempt is tried once more right away (a fresh connection - SES replaces the machines behind its
 *   endpoint), after a second failure the transport is left alone for PAUSE_SECONDS (an outage costs one wait, not one
 *   per e-mail) and the failure goes back to Messenger: every e-mail is a message, its retry sends it again (30 s,
 *   2 min, 8 min, 30 min) - no second provider (the Seznam fallback is gone: it got the account blocked on
 *   2026-10-19, README "Reliability").
 *
 * The pause is kept for the life of the worker on purpose (no ResetInterface): it holds no user data, and a reset
 * between messages would make every message wait for a dead transport again.
 */
final class MailStreamRouting implements TransportInterface
{
    public const int PAUSE_SECONDS = 60;

    private null|\DateTimeImmutable $pausedUntil = null;

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly EmailSuppressionRepository $emailSuppressionRepository,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function send(RawMessage $message, null|Envelope $envelope = null): null|SentMessage
    {
        if (!$message instanceof Message) {
            return $this->inner->send($message, $envelope);
        }

        $headers = $message->getHeaders();
        $stream = MailStream::fromTransportName($headers->get('X-Transport')?->getBodyAsString());

        $headers->remove('X-Transport');
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

        $now = $this->clock->now();

        if ($this->pausedUntil !== null && $now < $this->pausedUntil) {
            throw new TransportException(sprintf(
                'The mail transport is not tried until %s, it failed twice in a row',
                $this->pausedUntil->format(\DateTimeInterface::ATOM),
            ));
        }

        try {
            $sent = $this->inner->send($message, $envelope);
        } catch (TransportExceptionInterface $firstFailure) {
            if (self::isRecipientRejection($firstFailure) || self::isRefusal($firstFailure)) {
                throw $firstFailure;
            }

            $this->logger->info('Sending an e-mail failed, trying once more', [
                'exception' => $firstFailure,
            ]);

            try {
                $sent = $this->inner->send($message, $envelope);
            } catch (TransportExceptionInterface $secondFailure) {
                if (self::isRecipientRejection($secondFailure) || self::isRefusal($secondFailure)) {
                    throw $secondFailure;
                }

                $this->pausedUntil = $this->clock->now()->modify(sprintf('+%d seconds', self::PAUSE_SECONDS));

                $this->logger->warning('Sending e-mails failed twice in a row, the transport is not tried for a minute', [
                    'exception' => $secondFailure,
                    'first_failure' => $firstFailure->getMessage(),
                ]);

                throw $secondFailure;
            }
        }

        $this->pausedUntil = null;

        return $sent;
    }

    public function __toString(): string
    {
        return (string) $this->inner;
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
