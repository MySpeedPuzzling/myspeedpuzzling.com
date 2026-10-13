<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Mailer;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Value\MailStream;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Sits in front of the mailer's transports (decorates `mailer.transports`), where X-Transport still says which
 * stream a message belongs to:
 *
 * - marks the stream on the message for the SES headers (SesMessageHeaders::STREAM_HEADER);
 * - drops notifications to an address that hard-bounced (email_suppression) - transactional mail is still tried,
 *   a sign-in link must never be held back by us;
 * - test mode before SES production access (SES_TEST_RECIPIENTS): a message whose every recipient is on that list
 *   goes through the `ses` transport, everything else stays on its stream's transport. The sandbox delivers only to
 *   verified addresses, so this is how the whole chain is tried on production before the switch. The switch itself
 *   is the stream's DSN (MAILER_TRANSACTIONAL_DSN / MAILER_NOTIFICATIONS_DSN), no code.
 */
final readonly class MailStreamRouting implements TransportInterface
{
    public const string SES_TRANSPORT = 'ses';

    /** @var list<string> */
    private array $testRecipients;

    public function __construct(
        private TransportInterface $inner,
        private EmailSuppressionRepository $emailSuppressionRepository,
        private LoggerInterface $logger,
        string $sesTestRecipients,
        private string $sesDsn,
    ) {
        $this->testRecipients = array_values(array_filter(array_map(
            static fn (string $address): string => mb_strtolower(trim($address)),
            explode(',', $sesTestRecipients),
        )));
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

        $recipients = self::recipients($message, $envelope);

        if ($stream === MailStream::Notifications) {
            foreach ($recipients as $recipient) {
                if ($this->emailSuppressionRepository->isSuppressed($recipient)) {
                    $this->logger->info('Notification e-mail not sent: the address is suppressed after a hard bounce', [
                        'recipient' => $recipient,
                    ]);

                    return null;
                }
            }
        }

        if ($this->routesToSesForTesting($recipients)) {
            $headers->remove('X-Transport');
            $headers->addTextHeader('X-Transport', self::SES_TRANSPORT);
        }

        return $this->inner->send($message, $envelope);
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    /**
     * @param list<string> $recipients
     */
    private function routesToSesForTesting(array $recipients): bool
    {
        if ($this->testRecipients === [] || $recipients === [] || $this->sesDsn === '' || str_starts_with($this->sesDsn, 'null://')) {
            return false;
        }

        return array_diff($recipients, $this->testRecipients) === [];
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
