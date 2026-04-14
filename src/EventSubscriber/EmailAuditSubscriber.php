<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\CreateEmailAuditLog;
use SpeedPuzzling\Web\Message\RecordEmailSendFailure;
use SpeedPuzzling\Web\Message\RecordEmailSendSuccess;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Service\ResetInterface;

final class EmailAuditSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var array<int, string> spl_object_id => audit log UUID */
    private array $pendingAuditIds = [];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly string $bounceEmailDomain,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Run AFTER Symfony's MessageListener (priority 0) which applies
            // globally-configured headers like From. We need From to be set
            // before generating the Message-ID.
            MessageEvent::class => ['onMessage', -100],
            SentMessageEvent::class => ['onSentMessage', 0],
            FailedMessageEvent::class => ['onFailedMessage', 0],
        ];
    }

    public function onMessage(MessageEvent $event): void
    {
        if ($event->isQueued()) {
            return;
        }

        $message = $event->getMessage();

        if (!$message instanceof Email || $message->getTo() === []) {
            return;
        }

        try {
            // Ensure the message has a Message-ID header so we can correlate
            // bounces back to this audit log entry. Symfony adds one later in
            // SentMessage, but only to an inner clone — we'd lose the value.
            // generateMessageId() requires From or Sender to derive the domain part.
            $headers = $message->getHeaders();

            if (!$headers->has('Message-ID') && ($headers->has('From') || $headers->has('Sender'))) {
                $headers->addIdHeader('Message-ID', $message->generateMessageId());
            }

            $envelope = $this->messageBus->dispatch(new CreateEmailAuditLog(
                recipientEmail: $message->getTo()[0]->getAddress(),
                subject: $message->getSubject() ?? '',
                transportName: $event->getTransport(),
                emailType: $this->extractEmailType($message),
            ));

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);
            /** @var string $auditLogId */
            $auditLogId = $handledStamp->getResult();

            $this->pendingAuditIds[spl_object_id($message)] = $auditLogId;

            if ($this->bounceEmailDomain !== '') {
                $verpAddress = 'bounce+' . $auditLogId . '@' . $this->bounceEmailDomain;
                $event->getEnvelope()->setSender(new Address($verpAddress));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create email audit log', [
                'exception' => $e,
            ]);
        }
    }

    public function onSentMessage(SentMessageEvent $event): void
    {
        try {
            $sentMessage = $event->getMessage();
            $originalMessage = $sentMessage->getOriginalMessage();
            $objectId = spl_object_id($originalMessage);

            if (!isset($this->pendingAuditIds[$objectId])) {
                return;
            }

            // The Message-ID header was set in onMessage — this is what bounces reference.
            // SentMessage::getMessageId() may have been overwritten by the SMTP transport
            // with the MTA queue ID (parsed from "250 OK queued as XYZ" response).
            $messageIdHeader = $this->extractMessageIdHeader($originalMessage);
            $mtaOrHeader = $sentMessage->getMessageId();
            $mtaQueueId = ($messageIdHeader !== null && $mtaOrHeader !== $messageIdHeader) ? $mtaOrHeader : null;

            $this->messageBus->dispatch(new RecordEmailSendSuccess(
                auditLogId: $this->pendingAuditIds[$objectId],
                messageId: $messageIdHeader ?? $mtaOrHeader,
                mtaQueueId: $mtaQueueId,
                smtpDebugLog: $sentMessage->getDebug(),
            ));

            unset($this->pendingAuditIds[$objectId]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update email audit log on sent', [
                'exception' => $e,
            ]);
        }
    }

    public function onFailedMessage(FailedMessageEvent $event): void
    {
        try {
            $objectId = spl_object_id($event->getMessage());

            if (!isset($this->pendingAuditIds[$objectId])) {
                return;
            }

            $error = $event->getError();
            $debugLog = $error instanceof TransportExceptionInterface ? $error->getDebug() : null;

            $this->messageBus->dispatch(new RecordEmailSendFailure(
                auditLogId: $this->pendingAuditIds[$objectId],
                errorMessage: $error->getMessage(),
                smtpDebugLog: $debugLog,
            ));

            unset($this->pendingAuditIds[$objectId]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update email audit log on failure', [
                'exception' => $e,
            ]);
        }
    }

    public function reset(): void
    {
        $this->pendingAuditIds = [];
    }

    private function extractEmailType(Email $message): null|string
    {
        if (!$message instanceof TemplatedEmail) {
            return null;
        }

        $template = $message->getHtmlTemplate();

        if ($template === null) {
            return null;
        }

        return basename($template, '.html.twig');
    }

    private function extractMessageIdHeader(\Symfony\Component\Mime\RawMessage $message): null|string
    {
        if (!$message instanceof \Symfony\Component\Mime\Message) {
            return null;
        }

        $header = $message->getHeaders()->get('Message-ID');

        if (!$header instanceof \Symfony\Component\Mime\Header\IdentificationHeader) {
            return null;
        }

        return $header->getId();
    }
}
